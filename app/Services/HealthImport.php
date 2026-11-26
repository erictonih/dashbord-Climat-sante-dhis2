<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpError;
use App\Dhis2\Dhis2Client;
use App\Dhis2\Dhis2Repository;
use PDO;

/** Import DHIS2 → table `sante`, un lot de communes à la fois. */
final class HealthImport
{
    public const MAX_PERIODS = 12;

    /** @return string[] périodes mensuelles AAAAMM, bornes incluses */
    public static function months(string $start, string $end): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Ym', $start);
        $last = \DateTimeImmutable::createFromFormat('!Ym', $end);
        if (!$date || !$last || $date->format('Ym') !== $start || $last->format('Ym') !== $end || $date > $last) {
            throw new HttpError(422, 'invalid_input', 'Périodes invalides (format AAAAMM, début ≤ fin).');
        }
        $months = [];
        for (; $date <= $last; $date = $date->modify('+1 month')) {
            $months[] = $date->format('Ym');
            if (count($months) > self::MAX_PERIODS) {
                throw new HttpError(422, 'invalid_input', 'Au maximum ' . self::MAX_PERIODS . ' mois par requête.');
            }
        }
        return $months;
    }

    public function run(array $periods, int $limit, int $offset, ?string $commune = null, ?Dhis2Repository $repo = null): array
    {
        Database::ensureTechnicalTables();
        $pdo = Database::pdo();
        $dataset = Dhis2Client::datasetUid()
            ?? throw new HttpError(503, 'dataset_missing', "DHIS2_DATASET_UID n'est pas renseigné.");

        $diseaseByElement = $pdo->query('SELECT de_id, maladie FROM dhis2_de_mapping')->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!$diseaseByElement) {
            throw new HttpError(409, 'no_disease', 'Aucune maladie associée. Associez au moins un élément de donnée.');
        }

        if ($commune !== null) {
            $stmt = $pdo->prepare('SELECT commune, ou_id FROM dhis2_ou_mapping WHERE commune = ?');
            $stmt->execute([$commune]);
            $total = 1;
        } else {
            $total = (int) $pdo->query('SELECT COUNT(*) FROM dhis2_ou_mapping')->fetchColumn();
            $stmt = $pdo->prepare('SELECT commune, ou_id FROM dhis2_ou_mapping ORDER BY commune LIMIT ? OFFSET ?');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
        $mappings = $stmt->fetchAll();
        if (!$mappings) {
            throw new HttpError(409, 'no_commune', "Aucune commune associée à une unité d'organisation (ou lot vide).");
        }

        $repo ??= new Dhis2Repository();
        $response = $repo->dataValues($dataset, array_column($mappings, 'ou_id'), $periods);
        $sums = Dhis2Repository::aggregate($response['values']);

        $find = $pdo->prepare('SELECT id FROM sante WHERE commune = ? AND date = ? AND maladie = ? LIMIT 1');
        $insert = $pdo->prepare('INSERT INTO sante (commune, date, maladie, nb_cas) VALUES (?, ?, ?, ?)');
        $update = $pdo->prepare('UPDATE sante SET nb_cas = ? WHERE id = ?');

        $results = [];
        $pdo->beginTransaction();
        try {
            foreach ($mappings as $mapping) {
                $lines = 0;
                foreach ($sums[$mapping['ou_id']] ?? [] as $period => $elements) {
                    // Plusieurs éléments associés à la même maladie s'additionnent.
                    $byDisease = [];
                    foreach ($elements as $element => $value) {
                        if (!isset($diseaseByElement[$element])) continue;
                        $disease = $diseaseByElement[$element];
                        $byDisease[$disease] = ($byDisease[$disease] ?? 0) + $value;
                    }
                    $date = substr((string) $period, 0, 4) . '-' . substr((string) $period, 4, 2) . '-01';
                    foreach ($byDisease as $disease => $value) {
                        $find->execute([$mapping['commune'], $date, $disease]);
                        $id = $find->fetchColumn();
                        $find->closeCursor();
                        $id ? $update->execute([(int) round($value), $id])
                            : $insert->execute([$mapping['commune'], $date, $disease, (int) round($value)]);
                        $lines++;
                    }
                }
                $failed = in_array($mapping['ou_id'], $response['failed'], true);
                $results[] = ['commune' => $mapping['commune'], 'lignes' => $lines,
                    'erreur' => $failed ? 'refusée par DHIS2' : null];
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $next = $offset + count($mappings);
        return ['periodes' => $periods, 'resultats' => $results,
            'lot' => ['total' => $total, 'suivant' => $next, 'termine' => $commune !== null || $next >= $total]];
    }
}
