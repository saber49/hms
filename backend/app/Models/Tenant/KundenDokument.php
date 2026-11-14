<?php

namespace App\Models\Tenant;

class KundenDokument extends TenantModel
{
    protected $table = 'kunden_dokumente';
    const CREATED_AT = 'hochgeladen_am';

    protected $fillable = ['kunde_id', 'titel', 'dateiname', 'dateipfad', 'dateigroesse', 'dateityp'];
}
