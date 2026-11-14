<?php

namespace App\Models\Tenant;

class BestelleingangTag extends TenantModel
{
    protected $table = 'bestelleingang_tage';

    protected $fillable = [
        'eingang_id', 'tag_nr', 'datum', 'wochentag', 'zeit_von', 'zeit_bis',
        'soll_std', 'gesetzl_pause', 'menge', 'beschreibung', 'status', 'bemerkung',
    ];
}
