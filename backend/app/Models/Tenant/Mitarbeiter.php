<?php

namespace App\Models\Tenant;

class Mitarbeiter extends TenantModel
{
    protected $table = 'mitarbeiter';

    protected $fillable = [
        'dienst_nr', 'name', 'vorname',
        'anrede', 'geschlecht',
        'geburtstag', 'geburtsort', 'geburtsname', 'geburtsland',
        'staatsangehoerigkeit',
        'personalausweis_nr', 'personalausweis_gueltig_ab', 'personalausweis_gueltig_bis',
        'telefon', 'mobil', 'email',
        'strasse', 'plz', 'ort', 'land', 'bundesland',
        'bereich',
        'dfb_schulung', 'bewacherregister_nr', 'gewo_qualifikation', 'dfb_pin', 'quasod_module',
        'funkrufname', 'barcode', 'ordnernummer',
        'steuer_id', 'sv_nummer', 'krankenkasse',
        'bank_verbindung', 'steuerklasse', 'kinderzahl_freibetrag',
        'familienstand', 'eintritt', 'austritt', 'art_der_beschaeftigung',
        'urlaubstage', 'arbeitsstunden_typ', 'arbeitsstunden',
        'stundenlohn', 'lohn_typ', 'foto',
        'aktiv', 'password_hash', 'app_zugang', 'app_first_login', 'app_last_login', 'geaendert_am',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'aktiv' => 'boolean',
        'app_zugang' => 'boolean',
        'app_first_login' => 'datetime',
        'app_last_login' => 'datetime',
        'geburtstag' => 'date',
        'eintritt' => 'date',
        'austritt' => 'date',
        'dfb_schulung' => 'date',
        'personalausweis_gueltig_ab'  => 'date',
        'personalausweis_gueltig_bis' => 'date',
        'arbeitsstunden' => 'decimal:2',
        'stundenlohn' => 'decimal:2',
        'urlaubstage' => 'integer',
        'quasod_module' => 'array',
    ];

    public function dokumente()
    {
        return $this->hasMany(MitarbeiterDokument::class, 'dienst_nr', 'dienst_nr');
    }

    public function abwesenheiten()
    {
        return $this->hasMany(MitarbeiterAbwesenheit::class, 'dienst_nr', 'dienst_nr');
    }

    public function nachrichten()
    {
        return $this->hasMany(MitarbeiterNachricht::class, 'dienst_nr', 'dienst_nr');
    }
}
