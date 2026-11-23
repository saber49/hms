<?php

namespace App\Services\Qm;

use Carbon\CarbonInterface;

/**
 * Union of actual on-site attendance intervals, clipped to one operational
 * day. An incomplete clock-out is not evidence of continuous coverage.
 */
class WachbuchCoverage
{
    public static function pruefen(CarbonInterface $start, CarbonInterface $ende, array $intervalle): array
    {
        $von = $start->getTimestamp();
        $bis = $ende->getTimestamp();
        $intervalle = array_values(array_filter(array_map(function ($i) use ($von, $bis) {
            if (!$i['von'] || !$i['bis']) return null;
            $a = max($von, $i['von']->getTimestamp());
            $b = min($bis, $i['bis']->getTimestamp());
            return $b > $a ? [$a, $b] : null;
        }, $intervalle)));
        usort($intervalle, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $cursor = $von;
        $luecken = [];
        foreach ($intervalle as [$a, $b]) {
            if ($a > $cursor) $luecken[] = ['von' => gmdate('c', $cursor), 'bis' => gmdate('c', $a)];
            $cursor = max($cursor, $b);
        }
        if ($cursor < $bis) $luecken[] = ['von' => gmdate('c', $cursor), 'bis' => gmdate('c', $bis)];

        return ['luecken' => $luecken, 'lueckenlos' => !$luecken && $intervalle !== []];
    }
}
