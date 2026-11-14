<?php

namespace App\Models\Tenant;

class MitarbeiterNachricht extends TenantModel
{
    protected $table = 'mitarbeiter_nachrichten';

    protected $fillable = [
        'dienst_nr', 'richtung', 'nachricht', 'gelesen',
        'admin_user_id', 'datei_pfad', 'datei_name',
    ];

    protected $casts = ['gelesen' => 'boolean'];
}
