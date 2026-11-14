<?php

namespace App\Models\Tenant;

class MitarbeiterDokument extends TenantModel
{
    protected $table = 'mitarbeiter_dokumente';
    const CREATED_AT = 'hochgeladen_am';
    const UPDATED_AT = null; // table has no updated_at column

    protected $fillable = [
        'dienst_nr',
        'titel',
        'dok_typ',
        'dateiname',
        'dateipfad',
        'dateigroesse',
        'ablaufdatum',
        'gueltig_ab',
        'pflicht',
        'bemerkung',
    ];

    protected $casts = [
        'pflicht'      => 'boolean',
        'ablaufdatum'  => 'date',
        'gueltig_ab'   => 'date',
        'dateigroesse' => 'integer',
    ];

    public function mitarbeiter()
    {
        return $this->belongsTo(Mitarbeiter::class, 'dienst_nr', 'dienst_nr');
    }
}
