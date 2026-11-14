<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Interessentenmanagement (Lead-Modul) — Spezifikation 27.10.2026, Phase 1.
 *
 * Three levels: Kontakt (lifecycle_stage) → Lead (stage, per Sparte) → Deal (phase).
 *   crm_firmen        Firma 1:n Kontakt
 *   crm_kontakte      Kontakt 1:n Lead
 *   crm_leads         Lead 0..1 Deal
 *   crm_deals
 *   crm_aktivitaeten  n:1 Kontakt / Lead / Deal (timeline)
 *   crm_protokoll     append-only change log (lifecycle, stage, phase, owner)
 *   crm_listen        configurable value lists (stages, phases, reasons, sources …)
 *   crm_einstellungen configurable deadlines / thresholds
 *   aufgaben          (existing module) gains bezug_typ/bezug_id/typ/faellig_zeit
 *
 * Sparte = main Bereich of bereiche_katalog (name). owner_id / user_id are
 * central_users.id (cross-DB → plain bigint). Soft delete via deleted_at.
 * Idempotent: re-running creates nothing that already exists.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);

        if (!$s->hasTable('crm_listen')) {
            $s->create('crm_listen', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('typ', 30);
                $t->string('schluessel', 60);
                $t->string('name', 120);
                $t->unsignedInteger('reihenfolge')->default(0);
                $t->string('kategorie', 30)->nullable();
                $t->unsignedTinyInteger('wahrscheinlichkeit')->nullable();
                $t->json('zusatz')->nullable();
                $t->boolean('aktiv')->default(true);
                $t->boolean('system')->default(false);
                $t->timestamps();
                $t->unique(['typ', 'schluessel']);
            });
        }

        if (!$s->hasTable('crm_einstellungen')) {
            $s->create('crm_einstellungen', function (Blueprint $t) {
                $t->string('schluessel', 60)->primary();
                $t->string('wert', 500)->nullable();
                $t->timestamps();
            });
        }

        if (!$s->hasTable('crm_firmen')) {
            $s->create('crm_firmen', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name', 200);
                $t->string('strasse', 200)->nullable();
                $t->string('plz', 10)->nullable();
                $t->string('ort', 120)->nullable();
                $t->string('bundesland', 60)->nullable();
                $t->string('branche', 60)->nullable();
                $t->unsignedInteger('mitarbeiterzahl')->nullable();
                $t->string('telefon', 60)->nullable();
                $t->string('website', 200)->nullable();
                $t->unsignedBigInteger('owner_id');
                $t->string('kunde_id', 20)->nullable();
                $t->string('alt_interessent_id', 20)->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index('name');
                $t->index('plz');
                $t->index('owner_id');
            });
        }

        if (!$s->hasTable('crm_kontakte')) {
            $s->create('crm_kontakte', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('firma_id')->nullable();
                $t->string('anrede', 20)->nullable();
                $t->string('vorname', 100)->nullable();
                $t->string('nachname', 100);
                $t->string('position', 150)->nullable();
                $t->boolean('entscheider')->default(false);
                $t->string('email', 200)->nullable();
                $t->string('telefon', 60)->nullable();
                $t->string('mobil', 60)->nullable();
                $t->string('lifecycle_stage', 60);
                $t->dateTime('lifecycle_changed_at');
                $t->string('quelle', 60);
                $t->boolean('dsgvo_einwilligung')->default(false);
                $t->date('dsgvo_datum')->nullable();
                $t->unsignedBigInteger('owner_id');
                $t->string('alt_interessent_id', 20)->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index('firma_id');
                $t->index('email');
                $t->index('telefon');
                $t->index('mobil');
                $t->index('owner_id');
                $t->index('lifecycle_stage');
            });
        }

        if (!$s->hasTable('crm_leads')) {
            $s->create('crm_leads', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('kontakt_id');
                $t->uuid('firma_id')->nullable();
                $t->string('sparte', 190)->nullable();
                $t->string('stage', 60);
                $t->dateTime('stage_changed_at');
                $t->unsignedBigInteger('owner_id');
                $t->string('prioritaet', 60)->nullable();
                $t->unsignedInteger('anzahl_kontaktversuche')->default(0);
                $t->dateTime('letzte_aktivitaet_am')->nullable();
                $t->json('checkliste')->nullable();
                $t->string('disq_grund', 60)->nullable();
                $t->text('disq_notiz')->nullable();
                $t->date('wiedervorlage_am')->nullable();
                $t->uuid('duplikat_von')->nullable();
                $t->uuid('deal_id')->nullable();
                $t->string('alt_interessent_id', 20)->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index('kontakt_id');
                $t->index('firma_id');
                $t->index(['stage', 'owner_id']);
                $t->index('sparte');
                $t->index('alt_interessent_id');
            });
        }

        if (!$s->hasTable('crm_deals')) {
            $s->create('crm_deals', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('lead_id')->nullable();
                $t->uuid('firma_id')->nullable();
                $t->uuid('kontakt_id');
                $t->string('titel', 250);
                $t->string('sparte', 190)->nullable();
                $t->string('phase', 60);
                $t->dateTime('phase_changed_at');
                $t->string('objektadresse', 300)->nullable();
                $t->dateTime('besichtigung_am')->nullable();
                $t->text('besichtigung_notiz')->nullable();
                $t->decimal('wert_monatlich_eur', 12, 2)->nullable();
                $t->unsignedInteger('laufzeit_monate')->nullable();
                $t->date('erwarteter_abschluss')->nullable();
                $t->string('angebot_datei_pfad', 255)->nullable();
                $t->string('angebot_datei_name', 255)->nullable();
                $t->date('vertragsbeginn')->nullable();
                $t->string('verlust_grund', 60)->nullable();
                $t->text('verlust_notiz')->nullable();
                $t->unsignedBigInteger('owner_id');
                $t->string('kunde_id', 20)->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index('lead_id');
                $t->index('firma_id');
                $t->index('kontakt_id');
                $t->index(['phase', 'owner_id']);
                $t->index('sparte');
            });
        }

        if (!$s->hasTable('crm_aktivitaeten')) {
            $s->create('crm_aktivitaeten', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->uuid('kontakt_id')->nullable();
                $t->uuid('lead_id')->nullable();
                $t->uuid('deal_id')->nullable();
                $t->string('typ', 30);
                $t->string('richtung', 20)->nullable();
                $t->string('ergebnis', 60)->nullable();
                $t->dateTime('zeitpunkt');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->text('notiz')->nullable();
                $t->string('datei_disk', 20)->nullable();
                $t->string('datei_pfad', 255)->nullable();
                $t->string('datei_name', 255)->nullable();
                $t->string('regel', 10)->nullable();
                $t->timestamps();
                $t->index('kontakt_id');
                $t->index('lead_id');
                $t->index('deal_id');
                $t->index('zeitpunkt');
            });
        }

        if (!$s->hasTable('crm_protokoll')) {
            $s->create('crm_protokoll', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('objekt', 20);
                $t->uuid('objekt_id');
                $t->string('feld', 40);
                $t->string('alt', 250)->nullable();
                $t->string('neu', 250)->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('user_name', 200)->nullable();
                $t->string('regel', 10)->nullable();
                $t->dateTime('created_at');
                $t->index(['objekt', 'objekt_id']);
                $t->index(['feld', 'created_at']);
            });
        }

        if ($s->hasTable('aufgaben')) {
            $s->table('aufgaben', function (Blueprint $t) use ($s) {
                if (!$s->hasColumn('aufgaben', 'bezug_typ'))    $t->string('bezug_typ', 20)->nullable()->after('bereich');
                if (!$s->hasColumn('aufgaben', 'bezug_id'))     $t->uuid('bezug_id')->nullable()->after('bezug_typ');
                if (!$s->hasColumn('aufgaben', 'typ'))          $t->string('typ', 30)->nullable()->after('bezug_id');
                if (!$s->hasColumn('aufgaben', 'faellig_zeit')) $t->time('faellig_zeit')->nullable()->after('faellig_am');
            });
            $idx = collect(DB::connection($this->connection)->select("SHOW INDEX FROM aufgaben WHERE Key_name = 'aufgaben_bezug_index'"));
            if ($idx->isEmpty()) {
                $s->table('aufgaben', fn (Blueprint $t) => $t->index(['bezug_typ', 'bezug_id'], 'aufgaben_bezug_index'));
            }
        }

        $this->seed();
    }

    /** Start values from the specification — insertOrIgnore keeps edited rows. */
    private function seed(): void
    {
        $db = DB::connection($this->connection);
        $now = now();
        $rows = [];
        $add = function (string $typ, array $items) use (&$rows, $now) {
            foreach (array_values($items) as $i => $it) {
                [$key, $name, $kat, $wk, $zusatz] = array_pad($it, 5, null);
                $rows[] = [
                    'typ' => $typ, 'schluessel' => $key, 'name' => $name, 'reihenfolge' => ($i + 1) * 10,
                    'kategorie' => $kat, 'wahrscheinlichkeit' => $wk,
                    'zusatz' => $zusatz ? json_encode($zusatz) : null,
                    'aktiv' => 1, 'system' => in_array($typ, ['lifecycle', 'lead_stage', 'deal_phase', 'anruf_ergebnis'], true)
                        || in_array($key, ['sonstiges', 'nicht_erreichbar', 'vertrag_wettbewerber', 'dublette', 'website_formular', 'import'], true) ? 1 : 0,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        };

        $add('lifecycle', [
            ['interessent', 'Interessent'], ['mql', 'MQL'], ['sql', 'SQL'],
            ['opportunity', 'Opportunity'], ['kunde', 'Kunde'], ['ehemaliger_kunde', 'Ehemaliger Kunde'],
        ]);
        $add('lead_stage', [
            ['neu', 'Neu', 'nicht_begonnen'],
            ['kontaktversuch', 'Kontaktversuch', 'in_bearbeitung'],
            ['verbunden', 'Verbunden', 'in_bearbeitung'],
            ['qualifiziert', 'Qualifiziert', 'abgeschlossen'],
            ['disqualifiziert', 'Disqualifiziert', 'abgeschlossen'],
        ]);
        $add('deal_phase', [
            ['besichtigung_geplant', 'Besichtigung geplant', 'offen', 10],
            ['besichtigung_erfolgt', 'Besichtigung erfolgt', 'offen', 25],
            ['angebot_versendet', 'Angebot versendet', 'offen', 50],
            ['verhandlung', 'Verhandlung', 'offen', 75],
            ['gewonnen', 'Gewonnen', 'gewonnen', 100],
            ['verloren', 'Verloren', 'verloren', 0],
        ]);
        $add('disq_grund', [
            ['kein_bedarf', 'Kein Bedarf'],
            ['vertrag_wettbewerber', 'Vertrag mit Wettbewerber läuft', null, null, ['pflicht' => 'wiedervorlage']],
            ['ausserhalb_gebiet', 'Außerhalb Einsatzgebiet'],
            ['nicht_erreichbar', 'Nicht erreichbar', null, null, ['pflicht' => 'min_versuche']],
            ['kein_entscheider', 'Kein Entscheider erreichbar'],
            ['leistung_nicht', 'Leistung bieten wir nicht an', null, null, ['pflicht' => 'notiz']],
            ['dublette', 'Dublette', null, null, ['pflicht' => 'original']],
            ['sonstiges', 'Sonstiges', null, null, ['pflicht' => 'notiz']],
        ]);
        $add('verlust_grund', [
            ['preis', 'Preis'], ['wettbewerber', 'Wettbewerber'], ['kein_budget', 'Kein Budget'],
            ['projekt_gestoppt', 'Projekt gestoppt'], ['keine_rueckmeldung', 'Keine Rückmeldung'],
            ['sonstiges', 'Sonstiges', null, null, ['pflicht' => 'notiz']],
        ]);
        $add('quelle', [
            ['website_formular', 'Website-Formular'], ['telefon_eingehend', 'Telefon eingehend'],
            ['kaltakquise', 'Kaltakquise'], ['empfehlung', 'Empfehlung'], ['ausschreibung', 'Ausschreibung'],
            ['messe_event', 'Messe/Event'], ['import', 'Import'], ['sonstiges', 'Sonstiges'],
        ]);
        $add('branche', [
            ['gewerbe', 'Gewerbe'], ['oeffentlich', 'Öffentliche Hand'], ['wohnungswirtschaft', 'Wohnungswirtschaft'],
            ['gesundheit', 'Gesundheit & Pflege'], ['industrie', 'Industrie & Produktion'], ['handel', 'Handel'],
            ['logistik', 'Logistik'], ['hotel_gastro', 'Hotel & Gastronomie'], ['veranstaltung', 'Veranstaltung'],
            ['sonstiges', 'Sonstiges'],
        ]);
        $add('prioritaet', [['hoch', 'Hoch'], ['normal', 'Normal'], ['niedrig', 'Niedrig']]);
        $add('anruf_ergebnis', [
            ['erreicht', 'Erreicht'], ['nicht_erreicht', 'Nicht erreicht'], ['mailbox', 'Mailbox'],
            ['falsche_nummer', 'Falsche Nummer'], ['rueckruf_vereinbart', 'Rückruf vereinbart'],
        ]);
        $add('aufgabe_typ', [
            ['anruf', 'Anruf'], ['email', 'E-Mail'], ['termin', 'Termin'],
            ['wiedervorlage', 'Wiedervorlage'], ['sonstiges', 'Sonstiges'],
        ]);

        foreach (array_chunk($rows, 50) as $chunk) {
            $db->table('crm_listen')->insertOrIgnore($chunk);
        }

        $settings = [
            'a2_rueckruf_arbeitstage' => '1',
            'a7_vorlauf_tage'         => '90',
            'a8_inaktiv_tage'         => '7',
            'a9_versuche'             => '6',
            'a9_tage'                 => '21',
            'dsgvo_pruefung_monate'   => '24',
            'einsatzgebiet'           => 'Sachsen-Anhalt, Sachsen, Thüringen',
        ];
        foreach ($settings as $k => $v) {
            $db->table('crm_einstellungen')->insertOrIgnore(['schluessel' => $k, 'wert' => $v, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        foreach (['crm_protokoll', 'crm_aktivitaeten', 'crm_deals', 'crm_leads', 'crm_kontakte', 'crm_firmen', 'crm_einstellungen', 'crm_listen'] as $t) {
            $s->dropIfExists($t);
        }
        if ($s->hasTable('aufgaben')) {
            $s->table('aufgaben', function (Blueprint $t) use ($s) {
                if ($s->hasColumn('aufgaben', 'bezug_typ')) $t->dropIndex('aufgaben_bezug_index');
                foreach (['bezug_typ', 'bezug_id', 'typ', 'faellig_zeit'] as $c) {
                    if ($s->hasColumn('aufgaben', $c)) $t->dropColumn($c);
                }
            });
        }
    }
};
