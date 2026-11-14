<?php

namespace App\Models\Tenant;

class Nachunternehmer extends TenantModel
{
    protected $table = 'nachunternehmer';

    protected $fillable = [
        'nu_id', 'firmenname', 'ansprechpartner', 'telefon_email', 'bereich',
        'anschrift_typ', 'zusatz', 'strasse', 'plz', 'ort', 'land',
        'telefon1', 'telefon2', 'fax', 'mobil', 'email', 'website', 'notiz',
        'aktiv', 'portal_username', 'portal_password', 'portal_aktiv',
    ];

    protected $hidden = ['portal_password'];

    protected $casts = ['aktiv' => 'boolean', 'portal_aktiv' => 'boolean'];

    public function dokumente()
    {
        return $this->hasMany(NuDokument::class, 'nu_id', 'nu_id');
    }

    public function portalMitarbeiter()
    {
        return $this->hasMany(NuPortalMitarbeiter::class, 'nu_id', 'nu_id');
    }
}
