<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpError;
use App\Core\Request;
use App\Dhis2\Dhis2Client;
use App\Dhis2\Dhis2Repository;
use App\Services\ClimateImport;
use App\Services\CommuneService;
use App\Services\DashboardService;
use App\Services\HealthImport;
use App\Services\MappingService;

/** Points d'entrée JSON : validation des paramètres puis appel des services. */
final class Api
{
    /** @return array<string, array{0:string, 1?:int}> "MÉTHODE chemin" => [méthode, cache en secondes] */
    public static function routes(): array
    {
        return [
            'GET /api/status'                  => ['status'],
            'GET /api/dashboard/summary'       => ['summary', 60],
            'GET /api/dashboard/series'        => ['series', 60],
            'GET /api/dashboard/filters'       => ['filters', 120],
            'GET /api/correlation'             => ['correlation', 60],
            'GET /api/map'                     => ['map', 120],
            'GET /api/communes'                => ['communes'],
            'POST /api/communes'               => ['createCommune'],
            'GET /api/dhis2/overview'          => ['overview', 300],
            'GET /api/dhis2/dataset'           => ['dataset', 300],
            'GET /api/dhis2/orgunits'          => ['orgUnits', 300],
            'GET /api/dhis2/dataelements'      => ['dataElements', 300],
            'GET /api/dhis2/mappings'          => ['mappings'],
            'POST /api/dhis2/mappings'         => ['saveMapping'],
            'POST /api/dhis2/mappings/delete'  => ['deleteMapping'],
            'POST /api/dhis2/mappings/auto'    => ['autoMapping'],
            'POST /api/import/sante'           => ['importHealth'],
            'POST /api/import/climat'          => ['importClimate'],
        ];
    }

    /** Indique ce qui est configuré, sans jamais renvoyer de valeur secrète. */
    public function status(): array
    {
        $database = ['configuree' => Database::isConfigured(), 'accessible' => false];
        if ($database['configuree']) {
            try {
                Database::pdo()->query('SELECT 1 FROM communes LIMIT 1');
                $database['accessible'] = true;
            } catch (HttpError $e) {
                $database['message'] = $e->getMessage();
            } catch (\PDOException $e) {
                $database['message'] = 'Tables absentes : exécutez sql/schema.sql.';
            }
        }
        return ['base' => $database, 'dhis2' => [
            'configure' => Dhis2Client::isConfigured(), 'jeu_de_donnees' => Dhis2Client::datasetUid() !== null,
        ]];
    }

    public function summary(): array
    {
        return (new DashboardService())->summary(Request::string('commune'));
    }

    public function series(): array
    {
        return (new DashboardService())->series(Request::string('commune'), Request::int('mois', 6, 1, 60));
    }

    public function filters(): array
    {
        return (new DashboardService())->filters();
    }

    public function correlation(): array
    {
        return (new DashboardService())->correlation(
            Request::string('climat', 30) ?? 'temperature',
            Request::string('maladie', 100, true),
            Request::string('commune'));
    }

    public function map(): array
    {
        return (new DashboardService())->map();
    }

    public function communes(): array
    {
        return (new CommuneService())->page(Request::string('q', 80), Request::int('page', 1, 1, 100000));
    }

    public function createCommune(): array
    {
        (new CommuneService())->create([
            'code' => Request::string('code', 50),
            'nom' => Request::string('nom', 150, true),
            'district' => Request::string('district'),
            'region' => Request::string('region'),
            'latitude' => Request::float('latitude', -90, 90),
            'longitude' => Request::float('longitude', -180, 180),
        ]);
        return [];
    }

    private static function searchTerm(): string
    {
        $q = Request::string('q', 80, true);
        if (mb_strlen(Dhis2Client::filterValue($q)) < 2) {
            throw new HttpError(422, 'invalid_input', 'Saisissez au moins 2 caractères.');
        }
        return $q;
    }

    public function overview(): array
    {
        return (new Dhis2Repository())->overview();
    }

    public function dataset(): array
    {
        $uid = Request::string('id', 11, true);
        if (!Dhis2Client::isUid($uid)) throw new HttpError(422, 'invalid_input', 'Identifiant DHIS2 invalide.');
        return ['dataset' => (new Dhis2Repository())->dataset($uid)];
    }

    public function orgUnits(): array
    {
        return ['resultats' => (new Dhis2Repository())->searchOrgUnits(self::searchTerm())];
    }

    public function dataElements(): array
    {
        return ['resultats' => (new Dhis2Repository())->searchDataElements(self::searchTerm())];
    }

    public function mappings(): array
    {
        return (new MappingService())->all();
    }

    public function saveMapping(): array
    {
        $service = new MappingService();
        $uid = Request::string('id', 11, true);
        $name = Request::string('nom_dhis2', 255);
        match (Request::string('type', 10, true)) {
            'commune' => $service->mapOrgUnit(Request::string('commune', 150, true), $uid, $name),
            'maladie' => $service->mapDataElement($uid, $name, Request::string('maladie', 100, true)),
            default => throw new HttpError(422, 'invalid_input', 'Type de correspondance inconnu.'),
        };
        return [];
    }

    public function deleteMapping(): array
    {
        (new MappingService())->delete(Request::string('type', 10, true), Request::string('cle', 150, true));
        return [];
    }

    public function autoMapping(): array
    {
        $level = Config::int('DHIS2_NIVEAU_COMMUNE', 0);
        return (new MappingService())->auto(
            Request::int('limit', 20, 1, 40), Request::int('offset', 0, 0, 100000), $level ?: null);
    }

    public function importHealth(): array
    {
        $periods = HealthImport::months(Request::string('debut', 6, true), Request::string('fin', 6, true));
        return (new HealthImport())->run($periods,
            Request::int('limit', 20, 1, 100), Request::int('offset', 0, 0, 100000), Request::string('commune'));
    }

    public function importClimate(): array
    {
        $start = Request::date('debut', 'Y-m-d', date('Y-m-d', strtotime('-30 days')));
        $end = Request::date('fin', 'Y-m-d', date('Y-m-d'));
        return (new ClimateImport())->run(str_replace('-', '', $start), str_replace('-', '', $end),
            Request::int('limit', 20, 1, 50), Request::int('offset', 0, 0, 100000),
            Request::string('commune'), Request::string('mappees', 1) === '1');
    }
}
