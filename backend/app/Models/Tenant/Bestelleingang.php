<?php

namespace App\Models\Tenant;

class Bestelleingang extends TenantModel
{
    protected $table = 'bestelleingang';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'eingang_id', 'kunden_id', 'obj_id', 'monat', 'jahr',
        'zeit_von', 'zeit_bis', 'modell', 'kundenpreis', 'nu_id',
        'nu_preis', 'bemerkung', 'status',
    ];

    public function tage()
    {
        return $this->hasMany(BestelleingangTag::class, 'eingang_id', 'eingang_id');
    }

    public function kunde()
    {
        return $this->belongsTo(Kunde::class, 'kunden_id', 'kunde_id');
    }

    public function objekt()
    {
        return $this->belongsTo(Objekt::class, 'obj_id', 'obj_id');
    }

    public function nachunternehmer()
    {
        return $this->belongsTo(Nachunternehmer::class, 'nu_id', 'nu_id');
    }

    public function bestellung()
    {
        return $this->hasOne(Bestellung::class, 'eingang_id', 'eingang_id');
    }
}
