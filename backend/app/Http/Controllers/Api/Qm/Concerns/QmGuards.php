<?php

namespace App\Http\Controllers\Api\Qm\Concerns;

use App\Support\Qm\Qm;
use App\Support\Qm\QmRechte;
use App\Support\Qm\QmScope;

/** Server-side guards shared by every office QM controller. */
trait QmGuards
{
    /** 404 unless the Objekt is inside the user's QM scope (also via direct URL). */
    protected function objektOderFehler($u, ?string $objId)
    {
        return QmScope::allowsObjekt($u, $objId) ? null : response()->json(['error' => 'Objekt nicht gefunden'], 404);
    }

    protected function rechtOderFehler($u, string $aktion)
    {
        return QmRechte::darf($u, $aktion) ? null : response()->json([
            'error' => 'Dafür fehlt die Berechtigung (' . (QmRechte::STANDARD[$aktion][0] ?? $aktion) . ').',
        ], 403);
    }

    protected function nichtBereit()
    {
        return Qm::ready() ? null : response()->json(['error' => 'Qualitätsmanagement ist noch nicht eingerichtet.'], 409);
    }
}
