<?php

namespace App\Models\Tenant;

class Einsatz extends TenantModel
{
    protected $table = 'einsaetze';

    protected $fillable = [
        'einsatz_id', 'bestell_id', 'kunden_id', 'obj_id', 'nu_id',
        'datum', 'tag_nr', 'beschreibung', 'ist_start', 'ist_ende',
        'ist_std_menge', 'ist_betrag', 'pause_minuten', 'gesetzl_pause',
        'hms_bestaetigt', 'pruefung', 'bemerkung',
    ];

    public function bestellung()
    {
        return $this->belongsTo(Bestellung::class, 'bestell_id', 'bestell_id');
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

    public function mitarbeiter()
    {
        return $this->hasMany(EinsatzMitarbeiter::class, 'einsatz_id', 'einsatz_id');
    }
}
