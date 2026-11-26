<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Tâches planifiées : traitent des lots tant que le temps le permet et mémorisent un
 * curseur, pour couvrir toutes les communes au fil des jours sans dépasser la limite.
 */
final class CronRunner
{
    private const BUDGET = 20;

    public function run(string $job): array
    {
        Database::ensureTechnicalTables();
        return match ($job) {
            'sante' => $this->loop($job, fn(int $offset) => (new HealthImport())->run(
                HealthImport::months(date('Ym', strtotime('first day of last month')), date('Ym')), 20, $offset)),
            'climat' => $this->loop($job, fn(int $offset) => (new ClimateImport())->run(
                date('Ymd', strtotime('-10 days')), date('Ymd'), 10, $offset, null, true)),
            'climat-complet' => $this->loop($job, fn(int $offset) => (new ClimateImport())->run(
                date('Ymd', strtotime('-10 days')), date('Ymd'), 10, $offset)),
            'mapping' => $this->loop($job, function (int $offset) {
                $level = Config::int('DHIS2_NIVEAU_COMMUNE', 0);
                return (new MappingService())->auto(20, $offset, $level ?: null);
            }),
        };
    }

    private function loop(string $job, callable $step): array
    {
        $pdo = Database::pdo();
        $read = $pdo->prepare('SELECT valeur FROM app_state WHERE cle = ?');
        $read->execute(["cron_$job"]);
        $offset = (int) $read->fetchColumn();
        $write = $pdo->prepare('INSERT INTO app_state (cle, valeur) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)');

        $started = microtime(true);
        $batches = 0;
        do {
            try {
                $result = $step($offset);
            } catch (\App\Core\HttpError $e) {
                if ($e->errorCode !== 'no_commune' || $offset === 0) throw $e;
                $offset = 0; // curseur au-delà de la fin : on repart du début
                $write->execute(["cron_$job", '0']);
                continue;
            }
            $batches++;
            $done = $result['lot']['termine'];
            $offset = $done ? 0 : $result['lot']['suivant'];
            $write->execute(["cron_$job", (string) $offset]);
        } while (!($done ?? false) && microtime(true) - $started < self::BUDGET);

        return ['tache' => $job, 'lots' => $batches, 'curseur' => $offset];
    }
}
