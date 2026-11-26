<?php
declare(strict_types=1);

namespace App\Dhis2;

/** Requêtes métier vers DHIS2. */
final class Dhis2Repository
{
    public function __construct(private readonly Dhis2Client $client = new Dhis2Client()) {}

    /** État de l'instance : version, niveaux (avec exemples) et jeux de données. */
    public function overview(): array
    {
        $info = $this->client->get('system/info.json', ['fields' => 'version']);
        $meta = $this->client->getMany([
            'levels'   => ['organisationUnitLevels.json', ['fields' => 'name,level', 'order' => 'level:asc', 'paging' => 'false']],
            'datasets' => ['dataSets.json', ['fields' => 'id,name,periodType', 'order' => 'name:asc', 'pageSize' => 100]],
        ]);
        $levels = $meta['levels']['data']['organisationUnitLevels'] ?? [];

        $requests = [];
        foreach ($levels as $level) {
            $requests[$level['level']] = ['organisationUnits.json',
                ['filter' => 'level:eq:' . $level['level'], 'fields' => 'name', 'pageSize' => 5]];
        }
        $samples = $this->client->getMany($requests);

        return [
            'version' => $info['version'] ?? null,
            'niveaux' => array_map(fn($level) => [
                'level'    => $level['level'],
                'name'     => $level['name'],
                'total'    => $samples[$level['level']]['data']['pager']['total'] ?? null,
                'exemples' => array_column($samples[$level['level']]['data']['organisationUnits'] ?? [], 'name'),
            ], $levels),
            'jeux_de_donnees' => $meta['datasets']['data']['dataSets'] ?? [],
        ];
    }

    public function dataset(string $uid): array
    {
        return $this->client->get("dataSets/$uid.json",
            ['fields' => 'id,name,periodType,dataSetElements[dataElement[id,name]]']);
    }

    public function searchOrgUnits(string $query, int $limit = 20): array
    {
        return $this->client->get('organisationUnits.json', [
            'filter' => 'name:ilike:' . Dhis2Client::filterValue($query),
            'fields' => 'id,name,level,parent[name]', 'pageSize' => $limit,
        ])['organisationUnits'] ?? [];
    }

    public function searchDataElements(string $query, int $limit = 20): array
    {
        return $this->client->get('dataElements.json', [
            'filter' => 'name:ilike:' . Dhis2Client::filterValue($query),
            'fields' => 'id,name,valueType', 'pageSize' => $limit,
        ])['dataElements'] ?? [];
    }

    /**
     * Recherche en parallèle des unités d'un niveau pour plusieurs noms.
     * @param array<array-key,string> $names
     * @return array<array-key,?array> null = appel en échec
     */
    public function orgUnitsByNames(array $names, int $level): array
    {
        $requests = [];
        foreach ($names as $key => $name) {
            $requests[$key] = ['organisationUnits.json', [
                'filter' => ['name:ilike:' . Dhis2Client::filterValue($name), "level:eq:$level"],
                'fields' => 'id,name,level', 'pageSize' => 20,
            ]];
        }
        return array_map(
            fn($r) => $r['data'] === null ? null : ($r['data']['organisationUnits'] ?? []),
            $this->client->getMany($requests)
        );
    }

    /**
     * Valeurs d'un jeu de données pour plusieurs unités ET plusieurs périodes.
     * Les unités sont envoyées par paquets ; si un paquet est refusé (une unité non
     * autorisée suffit), ses unités sont rejouées une à une pour isoler la fautive.
     *
     * @return array{values: array<int,array>, failed: array<int,string>}
     */
    public function dataValues(string $dataset, array $orgUnits, array $periods, int $chunkSize = 20): array
    {
        $build = fn(array $units) => ['dataValueSets.json',
            ['dataSet' => $dataset, 'orgUnit' => $units, 'period' => $periods]];

        $chunks = array_chunk(array_values(array_unique($orgUnits)), $chunkSize);
        $responses = $this->client->getMany(array_map($build, $chunks));

        $values = [];
        $failed = [];
        $isolate = [];
        foreach ($responses as $i => $response) {
            if ($response['data'] !== null) {
                array_push($values, ...($response['data']['dataValues'] ?? []));
            } elseif (in_array($response['status'], [400, 403, 404, 409], true) && count($chunks[$i]) > 1) {
                array_push($isolate, ...$chunks[$i]);
            } elseif (in_array($response['status'], [400, 403, 404, 409], true)) {
                array_push($failed, ...$chunks[$i]);
            } else {
                throw Dhis2Client::error($response['status'], 'dataValueSets');
            }
        }

        if ($isolate) {
            $single = $this->client->getMany(array_map(fn($u) => $build([$u]), $isolate));
            foreach ($single as $i => $response) {
                if ($response['data'] !== null) {
                    array_push($values, ...($response['data']['dataValues'] ?? []));
                } else {
                    $failed[] = $isolate[$i];
                }
            }
        }

        // Aucune unité n'a abouti : c'est un problème global (jeu de données, droits…).
        if ($failed && count($failed) === count(array_unique($orgUnits))) {
            throw Dhis2Client::error($responses[0]['status'], 'dataValueSets');
        }

        return ['values' => $values, 'failed' => $failed];
    }

    /**
     * Somme par unité / période / élément (toutes catégories confondues).
     * Les valeurs non numériques ou supprimées sont ignorées.
     * @return array<string,array<string,array<string,float>>>
     */
    public static function aggregate(array $values): array
    {
        $sums = [];
        foreach ($values as $v) {
            if (!isset($v['orgUnit'], $v['period'], $v['dataElement'], $v['value'])
                || !is_numeric($v['value']) || !empty($v['deleted'])) continue;
            $ou = $v['orgUnit']; $pe = $v['period']; $de = $v['dataElement'];
            $sums[$ou][$pe][$de] = ($sums[$ou][$pe][$de] ?? 0.0) + (float) $v['value'];
        }
        return $sums;
    }
}
