<?php

namespace App\Console\Commands;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seed demo/test data into an EXISTING tenant — idempotent & collision-safe.
 *
 * Uses a dedicated "9xx" demo ID range and insertOrIgnore() so it never
 * overwrites or clashes with data already in the tenant, and can be re-run
 * safely. Intended for development/staging only (guarded by the central DB
 * name; override with --allow-prod).
 *
 * Usage:
 *   php artisan tenant:seed-demo <slug|owner-email>
 */
class SeedDemoData extends Command
{
    protected $signature = 'tenant:seed-demo
                            {tenant : Tenant slug OR owner email}
                            {--allow-prod : Permit running when the central DB is NOT a *staging* database}';

    protected $description = 'Seed demo/test data into an existing tenant (idempotent, collision-safe).';

    public function handle(): int
    {
        $arg = trim((string) $this->argument('tenant'));

        $centralDb = (string) config('database.connections.mysql.database');
        if (stripos($centralDb, 'staging') === false && !$this->option('allow-prod')) {
            $this->error("Refusing to run: central DB '{$centralDb}' is not a *staging* database.");
            $this->line('  Pass --allow-prod only if you REALLY intend to seed demo data here.');
            return self::FAILURE;
        }

        // Resolve tenant by slug first, then by owner email.
        $tenant = Tenant::where('slug', $arg)->first();
        if (!$tenant && filter_var($arg, FILTER_VALIDATE_EMAIL)) {
            $tenant = CentralUser::where('email', $arg)->first()?->tenant;
        }
        if (!$tenant) {
            $this->error("No tenant found for '{$arg}' (tried slug and owner email).");
            return self::FAILURE;
        }

        $this->info("Tenant  : {$tenant->name} (id {$tenant->id})");
        $this->info("Slug    : {$tenant->slug}");
        $this->info("Database: {$tenant->database}");
        $this->newLine();

        $this->info('Seeding demo data (idempotent)...');
        $counts = $this->seedDemoData($tenant);

        $this->newLine();
        $this->info('Done. Current row counts in this tenant:');
        $this->table(['Table', 'Total rows'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }

    /**
     * @return array<string,int> total row counts per table after seeding
     */
    private function seedDemoData(Tenant $tenant): array
    {
        TenantService::switchToTenant($tenant);
        $db  = DB::connection('tenant');
        $now = now();

        $db->transaction(function () use ($db, $now): void {
            // ---- Customers (kunden) — demo range K9xx ----
            $kunden = [
                ['K901', 'Müller Gebäudereinigung GmbH', 'Hauptstraße 12', '50667', 'Köln',       '0221-1234567', 'info@mueller-clean.de',         'Herr Müller',  'Reinigung'],
                ['K902', 'Stadtklinik Nord gGmbH',       'Klinikweg 3',     '40474', 'Düsseldorf', '0211-9876543', 'verwaltung@stadtklinik-nord.de','Frau Schmidt', 'Sicherheit'],
                ['K903', 'LogiTrans Spedition GmbH',     'Industriestr. 88','47057', 'Duisburg',   '0203-5551212', 'dispo@logitrans.de',            'Herr Yilmaz',  'Logistik'],
                ['K904', 'Rheinpark Hotel GmbH',         'Uferpromenade 1', '53111', 'Bonn',       '0228-444555',  'empfang@rheinpark-hotel.de',    'Frau Becker',  'Hotellerie'],
                ['K905', 'TechnoBau AG',                 'Baufeld 7',       '45128', 'Essen',      '0201-333444',  'bau@technobau.de',              'Herr Klein',   'Bau'],
            ];
            foreach ($kunden as $k) {
                $db->table('kunden')->insertOrIgnore([
                    'kunde_id' => $k[0], 'kundenname' => $k[1], 'strasse' => $k[2], 'plz' => $k[3],
                    'ort' => $k[4], 'telefon1' => $k[5], 'email' => $k[6], 'haupt_ap' => $k[7],
                    'bereich' => $k[8], 'land' => 'D', 'aktiv' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // ---- Sites (objekte) — demo range O9xx ----
            $objekte = [
                ['O901', 'K901', 'Bürogebäude City-Center', 'Reinigung',  850.00,  600.00, 250.00],
                ['O902', 'K901', 'Lagerhalle West',         'Reinigung', 1200.00,  900.00, 300.00],
                ['O903', 'K902', 'Klinik Hauptgebäude',     'Sicherheit',2400.00, 1800.00, 600.00],
                ['O904', 'K903', 'Logistikzentrum Ost',     'Logistik',  1500.00, 1100.00, 400.00],
                ['O905', 'K904', 'Hotel Rheinpark',         'Hotellerie', 980.00,  700.00, 280.00],
                ['O906', 'K905', 'Baustelle A40',           'Bau',       3200.00, 2500.00, 700.00],
            ];
            foreach ($objekte as $o) {
                $db->table('objekte')->insertOrIgnore([
                    'obj_id' => $o[0], 'kunden_id' => $o[1], 'objektname' => $o[2], 'bereich' => $o[3],
                    'modell' => 'Pro Einsatz', 'preis_kunde' => $o[4], 'nu_preis' => $o[5], 'gewinn' => $o[6],
                    'aktiv' => 1, 'created_at' => $now,
                ]);
            }

            // ---- Workers (mitarbeiter) — demo dienst_nr 90xx ----
            $mitarbeiter = [
                ['9001', 'Schneider', 'Thomas',  'Reinigung'],
                ['9002', 'Wagner',    'Julia',   'Reinigung'],
                ['9003', 'Hoffmann',  'Michael', 'Sicherheit'],
                ['9004', 'Koch',      'Sandra',  'Sicherheit'],
                ['9005', 'Bauer',     'Daniel',  'Logistik'],
                ['9006', 'Richter',   'Nicole',  'Logistik'],
                ['9007', 'Klein',     'Stefan',  'Hotellerie'],
                ['9008', 'Wolf',      'Anna',    'Hotellerie'],
                ['9009', 'Neumann',   'Markus',  'Bau'],
                ['9010', 'Schwarz',   'Laura',   'Bau'],
            ];
            $workerPw = Hash::make('worker123');
            $arten = ['Vollzeit', 'Teilzeit', 'Minijob'];
            $byBereich = [];
            foreach ($mitarbeiter as $i => $m) {
                $db->table('mitarbeiter')->insertOrIgnore([
                    'dienst_nr' => $m[0], 'name' => $m[1], 'vorname' => $m[2],
                    'telefon' => '0170-' . (1000000 + $i * 11111),
                    'email' => strtolower($m[2] . '.' . $m[1]) . '@editimghub.test',
                    'bereich' => $m[3],
                    'eintritt' => $now->copy()->subMonths(($i + 1) * 3)->toDateString(),
                    'art_der_beschaeftigung' => $arten[$i % 3],
                    'arbeitsstunden_typ' => 'Monat',
                    'arbeitsstunden' => [173, 100, 40][$i % 3],
                    'aktiv' => 1, 'app_zugang' => 1, 'password_hash' => $workerPw,
                    'created_at' => $now,
                ]);
                $byBereich[$m[3]][] = $m[0];
            }

            // ---- Extra tenant users (distinct demo usernames) ----
            $db->table('users')->insertOrIgnore([
                ['username' => 'demo_manager', 'password_hash' => Hash::make('manager123'), 'full_name' => 'Max Manager', 'role' => 'manager', 'aktiv' => 1, 'created_at' => $now],
                ['username' => 'demo_viewer',  'password_hash' => Hash::make('viewer123'),  'full_name' => 'Vera Viewer',  'role' => 'viewer',  'aktiv' => 1, 'created_at' => $now],
            ]);

            // ---- Orders (bestellungen) — demo range B9xx, one per site ----
            $bestellungen = [];
            foreach ($objekte as $idx => $o) {
                $bid = sprintf('B9%02d', $idx + 1);
                $db->table('bestellungen')->insertOrIgnore([
                    'bestell_id' => $bid, 'kunden_id' => $o[1], 'obj_id' => $o[0],
                    'datum_von' => $now->copy()->startOfMonth()->toDateString(),
                    'datum_bis' => $now->copy()->endOfMonth()->toDateString(),
                    'zeit_von' => '08:00', 'zeit_bis' => '16:00',
                    'menge_einheit' => 'Std', 'soll_std_betrag' => 8.00,
                    'status' => 'offen', 'created_at' => $now,
                ]);
                $bestellungen[] = ['bestell_id' => $bid, 'kunden_id' => $o[1], 'obj_id' => $o[0], 'bereich' => $o[3]];
            }

            // ---- Incoming orders (bestelleingang) + days — demo range BE9xx ----
            $weekdaysDe = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
            $eingaenge = [['BE901', 'K901', 'O901'], ['BE902', 'K902', 'O903'], ['BE903', 'K903', 'O904']];
            foreach ($eingaenge as $e) {
                $db->table('bestelleingang')->insertOrIgnore([
                    'eingang_id' => $e[0], 'kunden_id' => $e[1], 'obj_id' => $e[2],
                    'monat' => (int) $now->format('n'), 'jahr' => (int) $now->format('Y'),
                    'zeit_von' => '08:00', 'zeit_bis' => '16:00', 'modell' => 'Pro Einsatz',
                    'kundenpreis' => 500.00, 'status' => 'unbearbeitet', 'created_at' => $now,
                ]);
                for ($t = 1; $t <= 3; $t++) {
                    $d = $now->copy()->addDays($t);
                    $db->table('bestelleingang_tage')->insertOrIgnore([
                        'eingang_id' => $e[0], 'tag_nr' => $t, 'datum' => $d->toDateString(),
                        'wochentag' => $weekdaysDe[(int) $d->dayOfWeek],
                        'zeit_von' => '08:00', 'zeit_bis' => '16:00',
                        'soll_std' => 8.00, 'gesetzl_pause' => 30, 'menge' => 1,
                        'status' => 'offen', 'created_at' => $now,
                    ]);
                }
            }

            // ---- Assignments (einsaetze) + worker allocations — demo range E9xxx ----
            $ei = 9001;
            $offsets = [-10, -3, 4];
            foreach ($bestellungen as $b) {
                foreach ($offsets as $idx => $off) {
                    $eid = 'E' . $ei++;
                    $datum = $now->copy()->addDays($off)->toDateString();
                    $db->table('einsaetze')->insertOrIgnore([
                        'einsatz_id' => $eid, 'bestell_id' => $b['bestell_id'],
                        'kunden_id' => $b['kunden_id'], 'obj_id' => $b['obj_id'],
                        'datum' => $datum, 'tag_nr' => $idx + 1,
                        'beschreibung' => 'Demo-Einsatz ' . $b['bereich'],
                        'ist_start' => '08:00', 'ist_ende' => '16:00',
                        'ist_std_menge' => 8.00, 'gesetzl_pause' => 30, 'pause_minuten' => 30,
                        'hms_bestaetigt' => $off < 0 ? 'bestaetigt' : 'ausstehend',
                        'created_at' => $now,
                    ]);

                    $pool = $byBereich[$b['bereich']] ?? array_column($mitarbeiter, 0);
                    $sort = 0;
                    foreach (array_slice($pool, 0, 2) as $dienstNr) {
                        $db->table('einsatz_mitarbeiter')->insertOrIgnore([
                            'einsatz_id' => $eid, 'dienst_nr' => $dienstNr,
                            'ist_std' => 8.00, 'sortierung' => $sort++, 'created_at' => $now,
                        ]);
                    }
                }
            }
        });

        $tables = ['kunden', 'objekte', 'mitarbeiter', 'users', 'bestellungen', 'bestelleingang', 'bestelleingang_tage', 'einsaetze', 'einsatz_mitarbeiter'];
        $counts = [];
        foreach ($tables as $t) {
            $counts[$t] = (int) $db->table($t)->count();
        }
        return $counts;
    }
}
