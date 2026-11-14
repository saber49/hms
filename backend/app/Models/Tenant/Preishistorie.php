<?php

namespace App\Models\Tenant;

class Preishistorie extends TenantModel
{
    protected $table = 'preishistorie';

    protected $fillable = [
        'aenderungsdatum', 'obj_id', 'objektname', 'kunden_id',
        'alter_preis_kunde', 'neuer_preis_kunde', 'alter_nu_preis',
        'neuer_nu_preis', 'grund',
    ];
}
