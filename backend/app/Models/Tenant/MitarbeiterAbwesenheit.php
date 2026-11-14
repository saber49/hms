<?php

namespace App\Models\Tenant;

class MitarbeiterAbwesenheit extends TenantModel
{
    protected $table = 'mitarbeiter_abwesenheiten';
    const UPDATED_AT = null; // table has only created_at

    protected $fillable = [
        'dienst_nr', 'typ', 'status',
        'bezahlt', 'ganzer_tag',
        'startdatum', 'enddatum', 'startzeit', 'endzeit',
        'tage', 'beschreibung',
        'entschieden_von', 'entschieden_am', 'ablehnungsgrund',
    ];

    protected $casts = [
        'bezahlt'        => 'boolean',
        'ganzer_tag'     => 'boolean',
        'startdatum'     => 'date',
        'enddatum'       => 'date',
        'tage'           => 'decimal:1',
        'entschieden_am' => 'datetime',
    ];

    public function mitarbeiter()
    {
        return $this->belongsTo(Mitarbeiter::class, 'dienst_nr', 'dienst_nr');
    }
}
