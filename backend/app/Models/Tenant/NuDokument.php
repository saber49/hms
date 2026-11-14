<?php

namespace App\Models\Tenant;

class NuDokument extends TenantModel
{
    protected $table = 'nu_dokumente';
    const CREATED_AT = 'hochgeladen_am';

    protected $fillable = ['nu_id', 'titel', 'dok_typ', 'dateiname', 'dateipfad', 'dateigroesse', 'faellig_typ'];
}
