<?php

namespace App\Support;

class Percentages
{
    /**
     * Round each value's share of the total to a whole percent using the
     * largest-remainder method, so the parts always sum to exactly 100 —
     * independent round(share * 100) per value can drift off 100 (e.g.
     * counts 1/1/4 of 6 round independently to 17/17/67 = 101%).
     *
     * @param  array<int|string, int|float>  $values
     * @return array<int|string, int> same keys/order as $values
     */
    public static function apportion(array $values): array
    {
        $total = array_sum($values);

        if ($total <= 0) {
            return array_map(fn () => 0, $values);
        }

        $floors = [];
        $remainders = [];
        foreach ($values as $key => $value) {
            $share = ($value / $total) * 100;
            $floors[$key] = (int) floor($share);
            $remainders[$key] = $share - $floors[$key];
        }

        // Whatever floor() discarded, hand back one whole point at a time to
        // the values with the largest fractional remainder — the parts sum
        // to 100 by construction (floor never overshoots). Incrementing an
        // existing $floors key doesn't change its position, so $floors stays
        // in the caller's original key order throughout.
        arsort($remainders);
        $pointsToDistribute = 100 - array_sum($floors);
        foreach (array_slice(array_keys($remainders), 0, $pointsToDistribute) as $key) {
            $floors[$key]++;
        }

        return $floors;
    }
}
