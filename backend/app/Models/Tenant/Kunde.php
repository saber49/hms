<?php

namespace App\Models\Tenant;

class Kunde extends TenantModel
{
    protected $table = 'kunden';

    protected $fillable = [
        'kunde_id', 'kundenname', 'kunden_nr_kunde', 'kunden_nr_intern',
        'abrechnungstyp', 'anschrift_typ', 'zusatz', 'strasse', 'land',
        'plz', 'ort', 'telefon1', 'telefon2', 'fax', 'mobil', 'email',
        'rechnung_email', 'website', 'haupt_ap', 'notiz', 'bereich', 'aktiv',
    ];

    protected $casts = ['aktiv' => 'boolean'];

    public function objekte()
    {
        return $this->hasMany(Objekt::class, 'kunden_id', 'kunde_id');
    }

    public function dokumente()
    {
        return $this->hasMany(KundenDokument::class, 'kunde_id', 'kunde_id');
    }
}
