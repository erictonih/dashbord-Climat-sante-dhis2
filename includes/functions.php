<?php
/**
 * Fonctions utilitaires du projet
 */

require_once __DIR__ . '/../config/db.php';

/**
 * Calcule le coefficient de corrélation de Pearson entre deux séries.
 * @param array $x
 * @param array $y
 * @return float|null  Coefficient entre -1 et 1, ou null si non calculable
 */
function pearsonCorrelation(array $x, array $y): ?float
{
    $n = count($x);
    if ($n === 0 || $n !== count($y)) {
        return null;
    }

    $sumX = array_sum($x);
    $sumY = array_sum($y);
    $sumXY = 0;
    $sumX2 = 0;
    $sumY2 = 0;

    for ($i = 0; $i < $n; $i++) {
        $sumXY += $x[$i] * $y[$i];
        $sumX2 += $x[$i] * $x[$i];
        $sumY2 += $y[$i] * $y[$i];
    }

    $numerateur = ($n * $sumXY) - ($sumX * $sumY);
    $denominateur = sqrt((($n * $sumX2) - ($sumX * $sumX)) * (($n * $sumY2) - ($sumY * $sumY)));

    if ($denominateur == 0) {
        return null;
    }

    return round($numerateur / $denominateur, 4);
}

/**
 * Détermine le niveau de risque à partir d'un indice 0-100
 */
function niveauRisque(float $indice): string
{
    if ($indice < SEUIL_RISQUE_FAIBLE) return 'faible';
    if ($indice < SEUIL_RISQUE_MOYEN) return 'moyen';
    return 'eleve';
}

/**
 * Couleur associée à un niveau de risque (pour Leaflet)
 */
function couleurRisque(string $niveau): string
{
    return match ($niveau) {
        'faible' => '#28a745', // vert
        'moyen'  => '#ffc107', // jaune
        'eleve'  => '#dc3545', // rouge
        default  => '#6c757d', // gris (inconnu)
    };
}

/**
 * Réponse JSON standardisée pour les endpoints API
 */
function jsonResponse($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
