<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Lectures du tableau de bord et de la carte (requêtes agrégées, sans N+1). */
final class DashboardService
{
    public const CLIMATE_VARIABLES = ['temperature', 'precipitation', 'pluie', 'humidite', 'vent', 'rayonnement'];

    private function query(string $sql, array $params = []): array
    {
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private static function num(mixed $value, int $decimals = 1): ?float
    {
        return $value === null ? null : round((float) $value, $decimals);
    }

    /** Indicateurs : 30 derniers jours disponibles (climat) et dernier mois disponible (santé). */
    public function summary(?string $commune): array
    {
        $filter = $commune ? ' AND commune = ?' : '';
        $args = $commune ? [$commune] : [];

        $lastClimate = $this->query("SELECT MAX(date) AS d FROM climat WHERE 1=1$filter", $args)[0]['d'] ?? null;
        $climate = [];
        if ($lastClimate) {
            $climate = $this->query("SELECT AVG(temperature) t, AVG(precipitation) p, AVG(humidite) h
                FROM climat WHERE date > DATE_SUB(?, INTERVAL 30 DAY) AND date <= ?$filter",
                [$lastClimate, $lastClimate, ...$args])[0];
        }

        $lastHealth = $this->query("SELECT MAX(date) AS d FROM sante WHERE 1=1$filter", $args)[0]['d'] ?? null;
        $cases = null;
        $previous = null;
        if ($lastHealth) {
            $month = substr($lastHealth, 0, 7) . '-01';
            $row = $this->query("SELECT
                    SUM(CASE WHEN date >= ? THEN nb_cas ELSE 0 END) AS courant,
                    SUM(CASE WHEN date < ? THEN nb_cas END) AS precedent
                FROM sante WHERE date >= DATE_SUB(?, INTERVAL 1 MONTH) AND date < DATE_ADD(?, INTERVAL 1 MONTH)$filter",
                [$month, $month, $month, $month, ...$args])[0];
            $cases = (int) $row['courant'];
            $previous = $row['precedent'] === null ? null : (int) $row['precedent'];
        }

        return [
            'climat' => [
                'jusqu_au' => $lastClimate,
                'temperature' => self::num($climate['t'] ?? null),
                'precipitation' => self::num($climate['p'] ?? null),
                'humidite' => self::num($climate['h'] ?? null, 0),
            ],
            'sante' => [
                'mois' => $lastHealth ? substr($lastHealth, 0, 7) : null,
                'cas' => $cases,
                'cas_precedent' => $previous,
            ],
        ];
    }

    /** Séries : climat moyen par jour (toutes communes confondues si aucun filtre) et cas par mois. */
    public function series(?string $commune, int $months): array
    {
        $start = (new \DateTimeImmutable('first day of this month'))->modify("-$months months")->format('Y-m-d');
        $filter = $commune ? ' AND commune = ?' : '';
        $args = $commune ? [$start, $commune] : [$start];

        $climate = $this->query("SELECT date, AVG(temperature) t, AVG(precipitation) p
            FROM climat WHERE date >= ?$filter GROUP BY date ORDER BY date", $args);
        $health = $this->query("SELECT DATE_FORMAT(date, '%Y-%m') mois, maladie, SUM(nb_cas) total
            FROM sante WHERE date >= ?$filter GROUP BY mois, maladie ORDER BY mois", $args);

        return [
            'climat' => array_map(fn($r) => [
                'date' => $r['date'], 'temperature' => self::num($r['t'], 2), 'precipitation' => self::num($r['p'], 2),
            ], $climate),
            'maladies' => array_map(fn($r) => [
                'mois' => $r['mois'], 'maladie' => $r['maladie'], 'total' => (int) $r['total'],
            ], $health),
        ];
    }

    public function filters(): array
    {
        return [
            'communes' => array_column($this->query(
                'SELECT commune FROM climat UNION SELECT commune FROM sante ORDER BY commune LIMIT 3000'), 'commune'),
            'maladies' => array_column($this->query(
                'SELECT DISTINCT maladie FROM sante ORDER BY maladie LIMIT 100'), 'maladie'),
        ];
    }

    /** Corrélation mensuelle : moyenne climatique du mois vs total de cas du mois, par commune. */
    public function correlation(string $variable, string $disease, ?string $commune): array
    {
        if (!in_array($variable, self::CLIMATE_VARIABLES, true)) {
            throw new \App\Core\HttpError(422, 'invalid_input', 'Variable climatique invalide.');
        }
        $filter = $commune ? ' AND commune = ?' : '';
        // $variable provient exclusivement de la liste blanche ci-dessus.
        $rows = $this->query("SELECT c.valeur AS x, s.total AS y
            FROM (SELECT commune, DATE_FORMAT(date, '%Y-%m') mois, SUM(nb_cas) total
                  FROM sante WHERE maladie = ?$filter GROUP BY commune, mois) s
            JOIN (SELECT commune, DATE_FORMAT(date, '%Y-%m') mois, AVG($variable) valeur
                  FROM climat WHERE $variable IS NOT NULL$filter GROUP BY commune, mois) c
              ON c.commune = s.commune AND c.mois = s.mois",
            $commune ? [$disease, $commune, $commune] : [$disease]);

        $r = Statistics::pearson(
            array_map(fn($row) => (float) $row['x'], $rows),
            array_map(fn($row) => (float) $row['y'], $rows)
        );
        return [
            'nb_observations' => count($rows),
            'coefficient' => $r,
            'interpretation' => Statistics::describe($r),
        ];
    }

    /** Carte : dernière mesure climatique et cas du dernier mois disponible, par commune. */
    public function map(): array
    {
        $rows = $this->query('SELECT co.id, co.nom, co.district, co.region, co.latitude, co.longitude,
                cl.temperature, cl.precipitation, cl.humidite
            FROM communes co
            JOIN (SELECT commune, MAX(date) d FROM climat GROUP BY commune) last ON last.commune = co.nom
            JOIN climat cl ON cl.commune = last.commune AND cl.date = last.d
            WHERE co.latitude IS NOT NULL AND co.longitude IS NOT NULL
            ORDER BY co.nom');

        $month = $this->query("SELECT DATE_FORMAT(MAX(date), '%Y-%m-01') d FROM sante")[0]['d'] ?? null;
        $cases = [];
        if ($month) {
            foreach ($this->query('SELECT commune, SUM(nb_cas) total FROM sante WHERE date >= ? GROUP BY commune', [$month]) as $r) {
                $cases[$r['commune']] = (int) $r['total'];
            }
        }
        $max = $cases ? max($cases) : 0;

        $points = [];
        foreach ($rows as $row) {
            if (isset($points[$row['id']])) continue;
            $count = $cases[$row['nom']] ?? 0;
            $index = $max > 0 ? (int) round($count / $max * 100) : null;
            $points[$row['id']] = [
                'nom' => $row['nom'], 'district' => $row['district'], 'region' => $row['region'],
                'lat' => (float) $row['latitude'], 'lon' => (float) $row['longitude'],
                'temperature' => self::num($row['temperature']), 'precipitation' => self::num($row['precipitation']),
                'humidite' => self::num($row['humidite'], 0), 'cas' => $count, 'indice' => $index,
                'niveau' => $index === null ? 'inconnu' : Statistics::riskLevel($index),
            ];
        }

        return ['mois' => $month ? substr($month, 0, 7) : null, 'communes' => array_values($points)];
    }
}
