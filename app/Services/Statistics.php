<?php
declare(strict_types=1);

namespace App\Services;

final class Statistics
{
    /** Coefficient de Pearson, ou null s'il n'est pas calculable (moins de 3 points, série constante). */
    public static function pearson(array $x, array $y): ?float
    {
        $n = count($x);
        if ($n < 3 || $n !== count($y)) return null;
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = $sxx = $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $mx;
            $dy = $y[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }
        if ($sxx <= 1e-12 || $syy <= 1e-12) return null;
        return round($sxy / sqrt($sxx * $syy), 4);
    }

    public static function describe(?float $r): string
    {
        if ($r === null) return 'Non calculable (données insuffisantes)';
        $abs = abs($r);
        $strength = match (true) {
            $abs < 0.2 => 'très faible', $abs < 0.4 => 'faible', $abs < 0.6 => 'modérée',
            $abs < 0.8 => 'forte', default => 'très forte',
        };
        return 'Corrélation ' . ($r >= 0 ? 'positive' : 'négative') . ' ' . $strength;
    }

    /** Niveau de risque à partir d'un indice 0-100 (seuils : 33 et 66). */
    public static function riskLevel(float $index): string
    {
        return $index < 33 ? 'faible' : ($index < 66 ? 'moyen' : 'eleve');
    }
}
