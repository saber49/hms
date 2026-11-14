<?php

namespace App\Console\Commands;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Create a fully-populated DEMO tenant for development/testing.
 *
 * Builds the tenant + database, an owner login (central_users), a tenant-level
 * admin, two extra users (manager/viewer) and a realistic German staffing
 * dataset (customers, sites, workers, orders, incoming orders, assignments and
 * worker allocations).
 *
 * SAFETY: refuses to run unless the central database name contains "staging"
 * (override with --allow-prod). On staging the tenant database is suffixed with
 * "_staging" to honour the environment isolation convention (a non-suffixed DB
 * could later collide with a real production tenant of the same slug, since
 * both environments share one MySQL server).
 */
class SetupDemoTenant extends Command
{
    protected $signature = 'tenant:setup-demo
                            {company : Company name (single word recommended, e.g. EditImgHub)}
                            {email : Owner login email}
                            {password : Owner login password (min 8 chars)}
                            {--full-name= : Owner full name (default: company + " Admin")}
                            {--plan=enterprise : trial|basic|pro|enterprise}
                            {--fresh : Drop an existing tenant with the same slug/email and recreate}
                            {--allow-prod : Permit running when the central DB is NOT a *staging* database}';

    protected $description = 'Create a demo tenant (owner login + seeded test data) for development.';

    public function handle(): int
    {
        $company  = trim((string) $this->argument('company'));
        $email    = strtolower(trim((string) $this->argument('email')));
        $password = (string) $this->argument('password');
        $fullName = $this->option('full-name') ?: $company . ' Admin';
        $plan     = (string) $this->option('plan');

        // --- Validation -----------------------------------------------------
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email: {$email}");
            return self::FAILURE;
        }
        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }
        if (!array_key_exists($plan, (array) config('plans.plans'))) {
            $this->error("Unknown plan '{$plan}'. Use one of: " . implode(', ', array_keys((array) config('plans.plans'))));
            return self::FAILURE;
        }

        // --- Safety guard: staging only by default --------------------------
        $centralDb = (string) config('database.connections.mysql.database');
        $isStaging = stripos($centralDb, 'staging') !== false;
        if (!$isStaging && !$this->option('allow-prod')) {
            $this->error("Refusing to run: central DB '{$centralDb}' is not a *staging* database.");
            $this->line('  Pass --allow-prod only if you REALLY intend to seed demo data here.');
            return self::FAILURE;
        }

        $slug   = Str::slug($company);
        $dbBase = 'hms_tenant_' . str_replace('-', '_', $slug);
        $dbName = $isStaging ? $dbBase . '_staging' : $dbBase;

        $this->info("Company : {$company}");
        $this->info("Slug    : {$slug}");
        $this->info("Database: {$dbName}");
        $this->info("Central : {$centralDb}");
        $this->newLine();

        // --- Handle existing tenant / email ---------------------------------
        $existing = Tenant::where('slug', $slug)->first();
        $emailTaken = CentralUser::where('email', $email)->exists();

        if (($existing || $emailTaken) && !$this->option('fresh')) {
            $this->error('A tenant with this slug or a user with this email already exists.');
            $this->line('  Re-run with --fresh to drop and recreate it.');
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->warn('--fresh: removing any existing tenant/user with this slug/email...');
            if ($existing) {
                try {
                    $existing->database = $existing->database ?: $dbName;
                    TenantService::deleteTenantDatabase($existing);
                } catch (\Throwable $e) {
                    $this->warn('  Could not drop DB: ' . $e->getMessage());
                }
                CentralUser::where('tenant_id', $existing->id)->delete();
                $existing->delete();
            }
            // Drop the computed DB too (covers a half-created state) + free the email.
            try {
                DB::statement("DROP DATABASE IF EXISTS `{$dbName}`");
            } catch (\Throwable $e) {
                // ignore
            }
            CentralUser::where('email', $email)->delete();
        }

        // --- Create tenant + database (runs tenant migrations) --------------
        $planConfig = config("plans.plans.{$plan}");
        $trialDays  = (int) config('plans.trial_days', 14);

        $tenant = Tenant::create([
            'name'                 => $company,
            'slug'                 => $slug,
            'database'             => $dbName,
            'plan'                 => $plan,
            'aktiv'                => true,
            'onboarding_completed' => true,
            'trial_ends_at'        => $plan === 'trial' ? now()->addDays($trialDays) : null,
            'subscription_ends_at' => $plan === 'trial' ? null : now()->addYear(),
            'max_workers'          => $planConfig['max_workers'] ?? 10,
            'max_customers'        => $planConfig['max_customers'] ?? 20,
            'max_users'            => $planConfig['max_users'] ?? 3,
            // A complete company profile so PDF/branding features have data.
            'company_email'        => $email,
            'company_phone'        => '+49 221 1234567',
            'company_address'      => 'Musterstraße 1',
            'company_city'         => 'Köln',
            'company_zip'          => '50667',
            'company_country'      => 'Deutschland',
            'rechtsform'           => 'GmbH',
            'geschaeftsfuehrer'    => $fullName,
            'handelsregister_nr'   => 'HRB 12345',
            'registergericht'      => 'Amtsgericht Köln',
            'ust_id'               => 'DE123456789',
            'steuernummer'         => '215/5701/0500',
            'bank_name'            => 'Sparkasse KölnBonn',
            'iban'                 => 'DE89 3705 0198 0000 1234 56',
            'bic'                  => 'COLSDE33XXX',
            'website'              => 'https://editimghub.test',
        ]);

        $this->info('Creating database & running tenant migrations...');
        TenantService::createTenantDatabase($tenant);

        // Tenant-level admin (legacy users table) + central owner login.
        TenantService::seedTenantAdmin($tenant, 'admin', $password, $fullName);

        $owner = CentralUser::create([
            'tenant_id'         => $tenant->id,
            'name'              => Str::before($email, '@'),
            'email'             => $email,
            'password'          => Hash::make($password),
            'full_name'         => $fullName,
            'role'              => 'tenant_admin',
            'is_owner'          => true,
            'aktiv'             => true,
            'email_verified_at' => now(),
            'verification_token' => null,
        ]);

        $this->info('Seeding demo data...');
        $counts = $this->seedDemoData($tenant);

        // --- Summary --------------------------------------------------------
        $this->newLine();
        $this->info('Demo tenant ready.');
        $this->table(['Field', 'Value'], [
            ['Tenant ID', $tenant->id],
            ['Company', $tenant->name],
            ['Slug', $tenant->slug],
            ['Database', $tenant->database],
            ['Plan', $tenant->plan],
            ['Login email', $owner->email],
            ['Login password', $password],
            ['Owner', $owner->is_owner ? 'yes' : 'no'],
        ]);
        $this->table(['Demo table', 'Rows'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }

    /**
     * Insert a coherent, FK-valid German staffing dataset into the tenant DB.
     *
     * @return array<string,int> row counts per table
     */
    private function seedDemoData(Tenant $tenant): array
    {
        TenantService::switchToTenant($tenant);
        $db  = DB::connection('tenant');
        $now = now();

        return $db->transaction(function () use ($db, $now): array {
            // ---- Customers (kunden) ----
            $kunden = [
                ['K001', 'Müller Gebäudereinigung GmbH', 'Hauptstraße 12', '50667', 'Köln',        '0221-1234567', 'info@mueller-clean.de',        'Herr Müller',  'Reinigung'],
                ['K002', 'Stadtklinik Nord gGmbH',       'Klinikweg 3',     '40474', 'Düsseldorf',  '0211-9876543', 'verwaltung@stadtklinik-nord.de','Frau Schmidt', 'Sicherheit'],
                ['K003', 'LogiTrans Spedition GmbH',     'Industriestr. 88','47057', 'Duisburg',    '0203-5551212', 'dispo@logitrans.de',           'Herr Yilmaz',  'Logistik'],
                ['K004', 'Rheinpark Hotel GmbH',         'Uferpromenade 1', '53111', 'Bonn',        '0228-444555',  'empfang@rheinpark-hotel.de',   'Frau Becker',  'Hotellerie'],
                ['K005', 'TechnoBau AG',                 'Baufeld 7',       '45128', 'Essen',       '0201-333444',  'bau@technobau.de',             'Herr Klein',   'Bau'],
            ];
            foreach ($kunden as $k) {
                $db->table('kunden')->insert([
                    'kunde_id' => $k[0], 'kundenname' => $k[1], 'strasse' => $k[2], 'plz' => $k[3],
                    'ort' => $k[4], 'telefon1' => $k[5], 'email' => $k[6], 'haupt_ap' => $k[7],
                    'bereich' => $k[8], 'land' => 'D', 'aktiv' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // ---- Sites (objekte) : obj_id, kunden_id, name, bereich, preis_kunde, nu_preis, gewinn ----
            $objekte = [
                ['O001', 'K001', 'Bürogebäude City-Center', 'Reinigung',  850.00,  600.00, 250.00],
                ['O002', 'K001', 'Lagerhalle West',         'Reinigung', 1200.00,  900.00, 300.00],
                ['O003', 'K002', 'Klinik Hauptgebäude',     'Sicherheit',2400.00, 1800.00, 600.00],
                ['O004', 'K003', 'Logistikzentrum Ost',     'Logistik',  1500.00, 1100.00, 400.00],
                ['O005', 'K004', 'Hotel Rheinpark',         'Hotellerie', 980.00,  700.00, 280.00],
                ['O006', 'K005', 'Baustelle A40',           'Bau',       3200.00, 2500.00, 700.00],
            ];
            foreach ($objekte as $o) {
                $db->table('objekte')->insert([
                    'obj_id' => $o[0], 'kunden_id' => $o[1], 'objektname' => $o[2], 'bereich' => $o[3],
                    'modell' => 'Pro Einsatz', 'preis_kunde' => $o[4], 'nu_preis' => $o[5], 'gewinn' => $o[6],
                    'aktiv' => 1, 'created_at' => $now,
                ]);
            }

            // ---- Workers (mitarbeiter) ----
            $mitarbeiter = [
                ['1001', 'Schneider', 'Thomas',  'Reinigung'],
                ['1002', 'Wagner',    'Julia',   'Reinigung'],
                ['1003', 'Hoffmann',  'Michael', 'Sicherheit'],
                ['1004', 'Koch',      'Sandra',  'Sicherheit'],
                ['1005', 'Bauer',     'Daniel',  'Logistik'],
                ['1006', 'Richter',   'Nicole',  'Logistik'],
                ['1007', 'Klein',     'Stefan',  'Hotellerie'],
                ['1008', 'Wolf',      'Anna',    'Hotellerie'],
                ['1009', 'Neumann',   'Markus',  'Bau'],
                ['1010', 'Schwarz',   'Laura',   'Bau'],
            ];
            $workerPw = Hash::make('worker123');
            $arten = ['Vollzeit', 'Teilzeit', 'Minijob'];
            $byBereich = [];
            foreach ($mitarbeiter as $i => $m) {
                $db->table('mitarbeiter')->insert([
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

            // ---- Extra tenant users (admin already created by seedTenantAdmin) ----
            $db->table('users')->insert([
                ['username' => 'manager1', 'password_hash' => Hash::make('manager123'), 'full_name' => 'Max Manager', 'role' => 'manager', 'aktiv' => 1, 'created_at' => $now],
                ['username' => 'viewer1',  'password_hash' => Hash::make('viewer123'),  'full_name' => 'Vera Viewer',  'role' => 'viewer',  'aktiv' => 1, 'created_at' => $now],
            ]);

            // ---- Orders (bestellungen) : one per site ----
            $bestellungen = [];
            $bi = 1;
            foreach ($objekte as $o) {
                $bid = sprintf('B%04d', $bi++);
                $db->table('bestellungen')->insert([
                    'bestell_id' => $bid, 'kunden_id' => $o[1], 'obj_id' => $o[0],
                    'datum_von' => $now->copy()->startOfMonth()->toDateString(),
                    'datum_bis' => $now->copy()->endOfMonth()->toDateString(),
                    'zeit_von' => '08:00', 'zeit_bis' => '16:00',
                    'menge_einheit' => 'Std', 'soll_std_betrag' => 8.00,
                    'status' => 'offen', 'created_at' => $now,
                ]);
                $bestellungen[] = ['bestell_id' => $bid, 'kunden_id' => $o[1], 'obj_id' => $o[0], 'bereich' => $o[3]];
            }

            // ---- Incoming orders (bestelleingang) + days ----
            $weekdaysDe = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
            $eingaenge = [['BE0001', 'K001', 'O001'], ['BE0002', 'K002', 'O003'], ['BE0003', 'K003', 'O004']];
            $tageCount = 0;
            foreach ($eingaenge as $e) {
                $db->table('bestelleingang')->insert([
                    'eingang_id' => $e[0], 'kunden_id' => $e[1], 'obj_id' => $e[2],
                    'monat' => (int) $now->format('n'), 'jahr' => (int) $now->format('Y'),
                    'zeit_von' => '08:00', 'zeit_bis' => '16:00', 'modell' => 'Pro Einsatz',
                    'kundenpreis' => 500.00, 'status' => 'unbearbeitet', 'created_at' => $now,
                ]);
                for ($t = 1; $t <= 3; $t++) {
                    $d = $now->copy()->addDays($t);
                    $db->table('bestelleingang_tage')->insert([
                        'eingang_id' => $e[0], 'tag_nr' => $t, 'datum' => $d->toDateString(),
                        'wochentag' => $weekdaysDe[(int) $d->dayOfWeek],
                        'zeit_von' => '08:00', 'zeit_bis' => '16:00',
                        'soll_std' => 8.00, 'gesetzl_pause' => 30, 'menge' => 1,
                        'status' => 'offen', 'created_at' => $now,
                    ]);
                    $tageCount++;
                }
            }

            // ---- Assignments (einsaetze) + worker allocations (einsatz_mitarbeiter) ----
            $ei = 1;
            $einsatzCount = 0;
            $emCount = 0;
            $offsets = [-10, -3, 4]; // spread over recent past + near future
            foreach ($bestellungen as $b) {
                foreach ($offsets as $idx => $off) {
                    $eid = sprintf('E%05d', $ei++);
                    $datum = $now->copy()->addDays($off)->toDateString();
                    $db->table('einsaetze')->insert([
                        'einsatz_id' => $eid, 'bestell_id' => $b['bestell_id'],
                        'kunden_id' => $b['kunden_id'], 'obj_id' => $b['obj_id'],
                        'datum' => $datum, 'tag_nr' => $idx + 1,
                        'beschreibung' => 'Demo-Einsatz ' . $b['bereich'],
                        'ist_start' => '08:00', 'ist_ende' => '16:00',
                        'ist_std_menge' => 8.00, 'gesetzl_pause' => 30, 'pause_minuten' => 30,
                        'hms_bestaetigt' => $off < 0 ? 'bestaetigt' : 'ausstehend',
                        'created_at' => $now,
                    ]);
                    $einsatzCount++;

                    $pool = $byBereich[$b['bereich']] ?? array_column($mitarbeiter, 0);
                    $sort = 0;
                    foreach (array_slice($pool, 0, 2) as $dienstNr) {
                        $db->table('einsatz_mitarbeiter')->insert([
                            'einsatz_id' => $eid, 'dienst_nr' => $dienstNr,
                            'ist_std' => 8.00, 'sortierung' => $sort++, 'created_at' => $now,
                        ]);
                        $emCount++;
                    }
                }
            }

            return [
                'kunden'              => count($kunden),
                'objekte'             => count($objekte),
                'mitarbeiter'         => count($mitarbeiter),
                'users (extra)'       => 2,
                'bestellungen'        => count($bestellungen),
                'bestelleingang'      => count($eingaenge),
                'bestelleingang_tage' => $tageCount,
                'einsaetze'           => $einsatzCount,
                'einsatz_mitarbeiter' => $emCount,
            ];
        });
    }
}
