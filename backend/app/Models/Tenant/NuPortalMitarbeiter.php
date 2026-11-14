<?php

namespace App\Models\Tenant;

class NuPortalMitarbeiter extends TenantModel
{
    protected $table = 'nu_portal_mitarbeiter';

    protected $fillable = [
        'nu_id', 'vorname', 'name', 'personal_nr', 'geschlecht',
        'qualifikation', 'skills', 'telefon', 'email', 'bemerkung', 'aktiv',
    ];

    protected $casts = ['aktiv' => 'boolean'];
}
