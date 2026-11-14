<?php

namespace App\Support\Qm;

use App\Support\BereichScope;

/**
 * Object scope of the QM module — applied server-side on EVERY QM read and
 * write (spec §3: "Objekt-Rechte werden serverseitig geprüft").
 *
 *  - QMB / Büro: all objects (still limited by BereichScope).
 *  - Objektleiter only: objects whose qm_objekt_einstellungen.objektleiter_id
 *    is this user — nothing else, also not via direct URL.
 */
class QmScope
{
    public static function alleObjekte($u): bool
    {
        return (bool) array_intersect(QmRechte::rollen($u), ['qmb', 'buero']);
    }

    /** obj_ids of an Objektleiter, or null = all objects (Bereich-limited). */
    public static function eigeneObjekte($u): ?array
    {
        if (self::alleObjekte($u)) return null;
        if (!in_array('objektleiter', QmRechte::rollen($u), true)) return [];
        return Qm::memo('eigene.' . ($u->id ?? 0), fn () => Qm::t('qm_objekt_einstellungen')
            ->where('objektleiter_id', $u->id)->pluck('obj_id')->map(fn ($x) => (string) $x)->all());
    }

    /**
     * Limit a query to the user's objects. $objCol = column holding obj_id,
     * $objektAlias = alias of a joined `objekte` table (for the Bereich check).
     */
    public static function apply($q, $u, string $objCol, string $objektAlias = 'o')
    {
        [$sql, $params] = BereichScope::objektPredicate($u, $objektAlias);
        $q->whereRaw($sql, $params);
        $eigene = self::eigeneObjekte($u);
        if ($eigene !== null) {
            $eigene ? $q->whereIn($objCol, $eigene) : $q->whereRaw('1 = 0');
        }
        return $q;
    }

    public static function allowsObjekt($u, ?string $objId): bool
    {
        if (!$objId || !BereichScope::allowsObjekt($u, $objId)) return false;
        $eigene = self::eigeneObjekte($u);
        return $eigene === null || in_array($objId, $eigene, true);
    }

    /** Objects the user may see: [{obj_id, objektname, kundenname, ...}]. */
    public static function objekte($u)
    {
        return Qm::memo('objekte.' . ($u->id ?? 0), function () use ($u) {
            $q = Qm::t('objekte as o')
                ->leftJoin('kunden as k', 'k.kunde_id', '=', 'o.kunden_id')
                ->leftJoin('qm_objekt_einstellungen as qe', 'qe.obj_id', '=', 'o.obj_id')
                ->where('o.aktiv', 1);
            self::apply($q, $u, 'o.obj_id');
            return $q->orderBy('o.objektname')->get([
                'o.obj_id', 'o.objektname', 'o.adresse', 'o.plz', 'o.stadt', 'o.kunden_id', 'k.kundenname',
                'qe.wachbuch_aktiv', 'qe.waechterkontrolle_aktiv', 'qe.objektleiter_id',
            ]);
        });
    }
}
