<?php

namespace App\Models\Tenant;

class EinsatzMitarbeiter extends TenantModel
{
    protected $table = 'einsatz_mitarbeiter';

    protected $fillable = ['einsatz_id', 'dienst_nr', 'ist_std', 'sortierung'];

    public function einsatz()
    {
        return $this->belongsTo(Einsatz::class, 'einsatz_id', 'einsatz_id');
    }

    public function worker()
    {
        return $this->belongsTo(Mitarbeiter::class, 'dienst_nr', 'dienst_nr');
    }
}
