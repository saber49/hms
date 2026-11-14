<?php

namespace App\Models\Tenant;

class Liste extends TenantModel
{
    protected $table = 'listen';

    protected $fillable = ['kategorie', 'wert', 'beschreibung', 'aktiv', 'sortierung'];

    protected $casts = ['aktiv' => 'boolean'];
}
