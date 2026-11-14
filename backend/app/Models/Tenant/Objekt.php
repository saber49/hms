<?php

namespace App\Models\Tenant;

class Objekt extends TenantModel
{
    protected $table = 'objekte';

    protected $fillable = [
        'obj_id', 'kunden_id', 'obj_nr_kunde', 'obj_nr_intern', 'objektname',
        'art', 'bereich', 'modell', 'preis_kunde', 'nu_preis', 'gewinn', 'aktiv',
    ];

    protected $casts = [
        'preis_kunde' => 'decimal:2',
        'nu_preis' => 'decimal:2',
        'gewinn' => 'decimal:2',
        'aktiv' => 'boolean',
    ];

    public function kunde()
    {
        return $this->belongsTo(Kunde::class, 'kunden_id', 'kunde_id');
    }
}
