<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('objekte')
            && !Schema::connection('tenant')->hasColumn('objekte', 'bundesland')) {
            Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
                $table->string('bundesland', 2)->nullable()->after('stadt');
            });
        }
        if (Schema::connection('tenant')->hasColumn('objekte', 'bundesland')) {
            $cities = [
                'ST' => ['Bernburg (Saale)', 'Bitterfeld-Wolfen', 'Dessau', 'Dessau-Roßlau', 'Halberstadt',
                         'Halle', 'Halle (Saale)', 'Halle-Ammendorf', 'Halle-Südstadt', 'Kabelsketal',
                         'Landsberg / OT Peißen', 'Langenbogen', 'Teutschenthal', 'Weißenfels', 'Wittenberg'],
                'SN' => ['Delitzsch', 'Eilenburg', 'Leipzig', 'Markkleeberg', 'Markranstädt', 'Riesa', 'Schkeuditz'],
                'BW' => ['Dornstadt'], 'BY' => ['Kronach'], 'BB' => ['Luckenwalde'],
                'TH' => ['Unterwellenborn OT Kamsdorf'],
            ];
            foreach ($cities as $state => $names) {
                DB::connection('tenant')->table('objekte')->whereNull('bundesland')->whereIn('stadt', $names)
                    ->update(['bundesland' => $state]);
            }
        }
        if (!Schema::connection('tenant')->hasTable('feiertage')) return;

        $easterDates = [
            2024 => '2024-03-31', 2025 => '2025-04-20', 2026 => '2026-04-05',
            2027 => '2027-03-28', 2028 => '2028-04-16', 2029 => '2029-04-01',
            2030 => '2030-04-21',
        ];
        $fixed = [
            '01-06' => ['Heilige Drei Könige', ['BW', 'BY', 'ST']],
            '03-08' => ['Internationaler Frauentag', ['BE', 'MV']],
            '08-15' => ['Mariä Himmelfahrt', ['SL']],
            '09-20' => ['Weltkindertag', ['TH']],
            '10-31' => ['Reformationstag', ['BB', 'HB', 'HH', 'MV', 'NI', 'SN', 'ST', 'SH', 'TH']],
            '11-01' => ['Allerheiligen', ['BW', 'BY', 'NW', 'RP', 'SL']],
        ];
        $rows = [];
        $now = now();
        foreach ($easterDates as $year => $easter) {
            foreach ($fixed as $md => [$name, $states]) {
                foreach ($states as $state) {
                    $rows[] = ['datum' => $year . '-' . $md, 'name' => $name, 'region' => 'DE-' . $state];
                }
            }
            $easterTs = strtotime($easter);
            foreach (['BW', 'BY', 'HE', 'NW', 'RP', 'SL'] as $state) {
                $rows[] = ['datum' => date('Y-m-d', $easterTs + 60 * 86400), 'name' => 'Fronleichnam', 'region' => 'DE-' . $state];
            }
            $nov23 = strtotime($year . '-11-23');
            $daysBack = ((int) date('N', $nov23) - 3 + 7) % 7;
            if ($daysBack === 0) $daysBack = 7;
            $rows[] = ['datum' => date('Y-m-d', $nov23 - $daysBack * 86400), 'name' => 'Buß- und Bettag', 'region' => 'DE-SN'];
        }
        $rows = array_map(fn ($row) => $row + [
            'aktiv' => 1, 'created_at' => $now, 'updated_at' => $now,
        ], $rows);
        DB::connection('tenant')->table('feiertage')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('objekte')
            && Schema::connection('tenant')->hasColumn('objekte', 'bundesland')) {
            Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
                $table->dropColumn('bundesland');
            });
        }
    }
};
