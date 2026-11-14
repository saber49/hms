<?php

namespace App\Models\Tenant;

class Bestellung extends TenantModel
{
    protected $table = 'bestellungen';

    protected $fillable = [
        'bestell_id', 'eingang_id', 'kunden_id', 'obj_id', 'kunden_obj_nr',
        'monat', 'jahr', 'datum_von', 'datum_bis', 'zeit_von', 'zeit_bis',
        'menge_einheit', 'soll_std_betrag', 'status', 'nu_id',
    ];

    public function eingang()
    {
        return $this->belongsTo(Bestelleingang::class, 'eingang_id', 'eingang_id');
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

    public function einsaetze()
    {
        return $this->hasMany(Einsatz::class, 'bestell_id', 'bestell_id');
    }
}
