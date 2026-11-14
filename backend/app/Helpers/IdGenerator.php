<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class IdGenerator
{
    public static function next(string $table, string $column, string $prefix): string
    {
        $prefixLen = strlen($prefix) + 1;
        $likePattern = $prefix . '%';

        $maxNum = DB::connection('tenant')
            ->table($table)
            ->whereRaw("$column LIKE ?", [$likePattern])
            ->selectRaw("MAX(CAST(SUBSTRING($column, $prefixLen) AS UNSIGNED)) AS max_num")
            ->value('max_num');

        $num = $maxNum ? intval($maxNum) + 1 : 1;

        do {
            $candidate = $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
            $exists = DB::connection('tenant')->table($table)->where($column, $candidate)->exists();
            if (!$exists) return $candidate;
            $num++;
        } while (true);
    }

    public static function uniqueId(string $table, string $column, string $prefix): string
    {
        $maxAttempts = 20;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $id = $prefix . date('Ym') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $exists = DB::connection('tenant')->table($table)->where($column, $id)->exists();
            if (!$exists) return $id;
        }
        // Fallback with timestamp to guarantee uniqueness
        return $prefix . date('Ym') . '-' . time() . '-' . random_int(10, 99);
    }

    public static function einsatzId(string $datum): string
    {
        $dateStr = str_replace('-', '', $datum);
        $maxAttempts = 20;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $id = 'E-' . $dateStr . '-' . str_pad(random_int(1, 999), 3, '0', STR_PAD_LEFT);
            $exists = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)->exists();
            if (!$exists) return $id;
        }
        // Fallback with higher range
        return 'E-' . $dateStr . '-' . random_int(1000, 9999);
    }

    public static function objNrIntern(): string
    {
        $maxNum = DB::connection('tenant')
            ->table('objekte')
            ->whereRaw("obj_nr_intern LIKE 'HMS-OBJ-%'")
            ->selectRaw("MAX(CAST(SUBSTRING(obj_nr_intern, 9) AS UNSIGNED)) AS max_num")
            ->value('max_num');

        $nextNum = $maxNum ? intval($maxNum) + 1 : 1;
        $candidate = 'HMS-OBJ-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        $exists = DB::connection('tenant')->table('objekte')->where('obj_nr_intern', $candidate)->exists();
        if (!$exists) return $candidate;

        return 'HMS-OBJ-' . time();
    }
}
