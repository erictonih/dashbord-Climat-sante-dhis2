<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpError;
use App\Dhis2\Dhis2Client;
use App\Dhis2\Dhis2Repository;
use PDO;

/** Correspondances commune ↔ unité d'organisation et maladie ↔ élément de donnée. */
final class MappingService
{
    public function __construct()
    {
        Database::ensureTechnicalTables();
    }

    public function all(): array
    {
        $pdo = Database::pdo();
        return [
            'communes' => $pdo->query('SELECT commune, ou_id, ou_name FROM dhis2_ou_mapping ORDER BY commune')->fetchAll(),
            'maladies' => $pdo->query('SELECT de_id, de_name, maladie FROM dhis2_de_mapping ORDER BY maladie')->fetchAll(),
        ];
    }

    private static function assertUid(string $uid): void
    {
        if (!Dhis2Client::isUid($uid)) throw new HttpError(422, 'invalid_input', 'Identifiant DHIS2 invalide.');
    }

    public function mapOrgUnit(string $commune, string $uid, ?string $name): void
    {
        self::assertUid($uid);
        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT 1 FROM communes WHERE nom = ? LIMIT 1');
        $exists->execute([$commune]);
        if (!$exists->fetchColumn()) {
            throw new HttpError(422, 'unknown_commune', "La commune « $commune » n'existe pas dans la table communes.");
        }
        $pdo->prepare('INSERT INTO dhis2_ou_mapping (commune, ou_id, ou_name) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE ou_id = VALUES(ou_id), ou_name = VALUES(ou_name)')
            ->execute([$commune, $uid, $name]);
    }

    public function mapDataElement(string $uid, ?string $name, string $disease): void
    {
        self::assertUid($uid);
        Database::pdo()->prepare('INSERT INTO dhis2_de_mapping (de_id, de_name, maladie) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE de_name = VALUES(de_name), maladie = VALUES(maladie)')
            ->execute([$uid, $name, $disease]);
    }

    public function delete(string $type, string $key): void
    {
        $sql = match ($type) {
            'commune' => 'DELETE FROM dhis2_ou_mapping WHERE commune = ?',
            'maladie' => 'DELETE FROM dhis2_de_mapping WHERE de_id = ?',
            default => throw new HttpError(422, 'invalid_input', 'Type de correspondance inconnu.'),
        };
        Database::pdo()->prepare($sql)->execute([$key]);
    }

    public static function normalize(string $name): string
    {
        $name = str_replace(['’', "'", '-'], ' ', mb_strtolower(trim($name), 'UTF-8'));
        $name = strtr($name, ['à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'î'=>'i','ï'=>'i','í'=>'i','ô'=>'o','ö'=>'o','ó'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u','ñ'=>'n']);
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Mapping automatique d'un lot. Les communes ne sont associées que si la correspondance
     * est exacte et unique au niveau DHIS2_NIVEAU_COMMUNE.
     */
    public function auto(int $limit, int $offset, ?int $level, ?Dhis2Repository $repo = null): array
    {
        $repo ??= new Dhis2Repository();
        $pdo = Database::pdo();

        $diseases = ['mappees' => [], 'ignorees' => []];
        $known = $pdo->query('SELECT DISTINCT maladie FROM dhis2_de_mapping')->fetchAll(PDO::FETCH_COLUMN);
        foreach (require __DIR__ . '/../maladies.php' as $disease => $keyword) {
            if (in_array($disease, $known, true)) continue;
            $results = $repo->searchDataElements($keyword, 5);
            if (!$results) {
                $diseases['ignorees'][] = ['maladie' => $disease, 'raison' => 'aucun résultat DHIS2'];
                continue;
            }
            $this->mapDataElement($results[0]['id'], $results[0]['name'], $disease);
            $diseases['mappees'][] = ['maladie' => $disease, 'de_name' => $results[0]['name'], 'ambigu' => count($results) > 1];
        }

        $communes = ['mappees' => [], 'ignorees' => []];
        $processed = 0;
        if ($level === null) {
            $communes['ignorees'][] = ['commune' => '—', 'raison' => "DHIS2_NIVEAU_COMMUNE n'est pas défini."];
        } else {
            $stmt = $pdo->prepare("SELECT DISTINCT co.nom FROM communes co
                LEFT JOIN dhis2_ou_mapping m ON m.commune = co.nom
                WHERE m.commune IS NULL AND co.nom <> '' ORDER BY co.nom LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $processed = count($names);

            foreach ($repo->orgUnitsByNames($names, $level) as $i => $results) {
                $name = $names[$i];
                if ($results === null) {
                    $communes['ignorees'][] = ['commune' => $name, 'raison' => 'appel DHIS2 en échec'];
                    continue;
                }
                $exact = array_values(array_filter($results, fn($ou) => self::normalize($ou['name']) === self::normalize($name)));
                if (count($exact) === 1) {
                    $this->mapOrgUnit($name, $exact[0]['id'], $exact[0]['name']);
                    $communes['mappees'][] = ['commune' => $name, 'ou_name' => $exact[0]['name']];
                } else {
                    $communes['ignorees'][] = ['commune' => $name, 'raison' => match (true) {
                        count($exact) > 1 => 'plusieurs unités DHIS2 identiques à ce niveau',
                        count($results) > 0 => 'aucun nom exact au niveau ciblé',
                        default => 'aucune unité DHIS2 trouvée',
                    }];
                }
            }
        }

        // Les communes mappées sortent de la liste : seules les ignorées décalent l'offset.
        $next = $offset + $processed - count($communes['mappees']);
        return ['maladies' => $diseases, 'communes' => $communes,
            'lot' => ['traitees' => $processed, 'suivant' => $next, 'termine' => $processed < $limit]];
    }
}
