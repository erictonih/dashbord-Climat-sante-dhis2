<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpError;
use PDO;

/** Import NASA POWER → table `climat`, un lot de communes à la fois. */
final class ClimateImport
{
    public function run(string $start, string $end, int $limit, int $offset, ?string $commune = null, bool $onlyMapped = false): array
    {
        $from = \DateTimeImmutable::createFromFormat('!Ymd', $start);
        $to = \DateTimeImmutable::createFromFormat('!Ymd', $end);
        if (!$from || !$to || $from > $to || $from->diff($to)->days > 366) {
            throw new HttpError(422, 'invalid_input', "Période invalide (début ≤ fin, un an au maximum).");
        }

        $pdo = Database::pdo();
        $source = 'FROM communes co ';
        if ($onlyMapped) {
            Database::ensureTechnicalTables();
            $source .= 'JOIN dhis2_ou_mapping m ON m.commune = co.nom ';
        }
        $source .= 'WHERE co.latitude IS NOT NULL AND co.longitude IS NOT NULL';

        if ($commune !== null) {
            $stmt = $pdo->prepare('SELECT nom, latitude, longitude FROM communes
                WHERE nom = ? AND latitude IS NOT NULL AND longitude IS NOT NULL LIMIT 1');
            $stmt->execute([$commune]);
            $total = 1;
        } else {
            $total = (int) $pdo->query("SELECT COUNT(*) $source")->fetchColumn();
            $stmt = $pdo->prepare("SELECT co.nom, co.latitude, co.longitude $source ORDER BY co.id LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
        $communes = $stmt->fetchAll();
        if (!$communes) throw new HttpError(409, 'no_commune', 'Aucune commune géolocalisée dans ce lot.');

        $nasa = new NasaPower();
        $started = microtime(true);
        $results = [];
        // Groupes de 5 appels parallèles ; on s'arrête avant la limite d'exécution et le client reprend.
        foreach (array_chunk($communes, 5) as $group) {
            if ($results && microtime(true) - $started > 35) break;
            foreach ($nasa->fetch($group, $start, $end) as $i => $parameters) {
                $name = $group[$i]['nom'];
                $results[] = $parameters === null
                    ? ['commune' => $name, 'lignes' => 0, 'erreur' => 'NASA POWER indisponible']
                    : ['commune' => $name, 'lignes' => $this->store($pdo, $name, $parameters), 'erreur' => null];
            }
        }

        $next = $offset + count($results);
        return ['periode' => ['debut' => $start, 'fin' => $end], 'resultats' => $results,
            'lot' => ['total' => $total, 'suivant' => $next, 'termine' => $commune !== null || $next >= $total]];
    }

    private function store(PDO $pdo, string $commune, array $parameters): int
    {
        $dates = array_keys($parameters['T2M'] ?? []);
        if (!$dates) return 0;

        $iso = fn($d) => substr((string) $d, 0, 4) . '-' . substr((string) $d, 4, 2) . '-' . substr((string) $d, 6, 2);
        $existing = $pdo->prepare('SELECT date, id FROM climat WHERE commune = ? AND date BETWEEN ? AND ?');
        $existing->execute([$commune, $iso(min($dates)), $iso(max($dates))]);
        $ids = $existing->fetchAll(PDO::FETCH_KEY_PAIR);

        $insert = $pdo->prepare('INSERT INTO climat (temperature, precipitation, pluie, humidite, vent, rayonnement, commune, date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $update = $pdo->prepare('UPDATE climat SET temperature = ?, precipitation = ?, pluie = ?, humidite = ?,
            vent = ?, rayonnement = ? WHERE id = ?');

        $count = 0;
        $pdo->beginTransaction();
        try {
            foreach ($dates as $raw) {
                // -999 = valeur manquante chez NASA POWER.
                $get = function (string $key) use ($parameters, $raw) {
                    $v = $parameters[$key][$raw] ?? null;
                    return ($v === null || (float) $v <= -900) ? null : $v;
                };
                $rain = $get('PRECTOTCORR');
                $values = [$get('T2M'), $rain, $rain, $get('RH2M'), $get('WS2M'), $get('ALLSKY_SFC_SW_DWN')];
                // Un jour pas encore publié ne doit pas effacer des valeurs existantes.
                if (!array_filter($values, fn($v) => $v !== null)) continue;
                $date = $iso($raw);
                isset($ids[$date])
                    ? $update->execute([...$values, $ids[$date]])
                    : $insert->execute([...$values, $commune, $date]);
                $count++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $count;
    }
}
