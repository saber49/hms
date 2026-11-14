<?php

namespace App\Services\Qm;

use App\Models\Tenant;
use App\Services\TenantService;
use App\Support\Qm\Qm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Objekt-Terminal (spec §5): Objekt-Kennung + 6-digit PIN opens ONLY the
 * Wachbuch of that one Objekt. Sessions end after N hours; every login is
 * logged; N wrong PINs lock the terminal and alert the Objektleitung.
 *
 * Tokens use the same central personal_access_tokens scheme as the worker
 * app and the portals (tokenable_type 'qm_terminal', id = session row).
 */
class TerminalService
{
    public const TOKEN_TYPE = 'qm_terminal';

    public static function kennungVorschlag(string $objId): string
    {
        $tenant = TenantService::current();
        $obj = Qm::t('objekte')->where('obj_id', $objId)->value('objektname') ?? $objId;
        $p = fn ($s, $n) => Str::of(Str::ascii((string) $s))->upper()->replaceMatches('/[^A-Z0-9]+/', '')->substr(0, $n)->value();
        $base = trim(($p($tenant->slug ?? 'CX', 4) ?: 'CX') . '-' . ($p($obj, 8) ?: 'OBJ'), '-');
        for ($i = 1; $i < 100; $i++) {
            $k = $base . '-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            if (!Qm::t('qm_terminals')->where('kennung', $k)->exists()) return $k;
        }
        return $base . '-' . Str::upper(Str::random(4));
    }

    /** Create the terminal or issue a new PIN. Returns [terminal, plainPin]. */
    public static function neuePin(string $objId, $u, ?string $kennung = null): array
    {
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $t = Qm::t('qm_terminals')->where('obj_id', $objId)->first();
        if ($t) {
            Qm::t('qm_terminals')->where('id', $t->id)->update([
                'pin_hash' => Hash::make($pin), 'fehlversuche' => 0, 'gesperrt_seit' => null, 'aktiv' => 1, 'updated_at' => now(),
            ]);
            self::sitzungenBeenden((int) $t->id);
        } else {
            Qm::t('qm_terminals')->insert([
                'obj_id' => $objId, 'kennung' => $kennung ?: self::kennungVorschlag($objId), 'pin_hash' => Hash::make($pin),
                'aktiv' => 1, 'created_by' => $u->id ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $t = Qm::t('qm_terminals')->where('obj_id', $objId)->first();
        Qm::log('terminal', $t->id, 'neue_pin', null, null, $u);
        return [$t, $pin];
    }

    public static function setAktiv(object $t, bool $aktiv, $u): void
    {
        Qm::t('qm_terminals')->where('id', $t->id)->update(['aktiv' => $aktiv ? 1 : 0, 'updated_at' => now()]
            + ($aktiv ? ['gesperrt_seit' => null, 'fehlversuche' => 0] : []));
        if (!$aktiv) self::sitzungenBeenden((int) $t->id);
        Qm::log('terminal', $t->id, $aktiv ? 'entsperrt' : 'gesperrt', null, null, $u);
    }

    public static function sitzungenBeenden(int $terminalId): void
    {
        $ids = Qm::t('qm_terminal_sitzungen')->where('terminal_id', $terminalId)->whereNull('ende')->pluck('id');
        if ($ids->isEmpty()) return;
        Qm::t('qm_terminal_sitzungen')->whereIn('id', $ids)->update(['ende' => now()]);
        DB::connection('mysql')->table('personal_access_tokens')->where('tokenable_type', self::TOKEN_TYPE)
            ->where('abilities', 'like', '%"tenant_id":' . (TenantService::current()->id ?? 0) . '}')
            ->whereIn('tokenable_id', $ids)->delete();
    }

    /** @return array{0: ?array, 1: ?array} [[token, objekt], [error, status]] */
    public static function login(string $kennung, string $pin, ?string $geraet, ?string $ip): array
    {
        $kennung = Str::upper(trim($kennung));
        $treffer = null;
        $gesperrt = false;
        foreach (Tenant::where('aktiv', true)->get() as $tenant) {
            try {
                TenantService::switchToTenant($tenant);
                if (!Schema::connection('tenant')->hasTable('qm_terminals')) continue;
                $t = Qm::t('qm_terminals')->where('kennung', $kennung)->first();
                if (!$t || !$t->pin_hash) continue;
                if (!$t->aktiv || $t->gesperrt_seit) { $gesperrt = true; continue; }
                if (!Hash::check($pin, $t->pin_hash)) { self::fehlversuch($t); continue; }
                $treffer = [$tenant, $t];
                break;
            } catch (\Throwable $e) {
                continue;
            }
        }
        if (!$treffer) {
            return [null, $gesperrt
                ? ['Dieser Terminal-Zugang ist gesperrt. Die Objektleitung kann ihn entsperren.', 423]
                : ['Objekt-Kennung oder PIN falsch.', 401]];
        }

        [$tenant, $t] = $treffer;
        TenantService::switchToTenant($tenant);
        $plain = bin2hex(random_bytes(32));
        $sid = Qm::t('qm_terminal_sitzungen')->insertGetId([
            'terminal_id' => $t->id, 'token_hash' => hash('sha256', $plain), 'start' => now(),
            'laeuft_ab' => now()->addHours(max(1, Qm::intSetting('terminal_sitzung_std', 12))),
            'geraet' => $geraet ? mb_substr($geraet, 0, 255) : null, 'ip' => $ip,
        ]);
        DB::connection('mysql')->table('personal_access_tokens')->insert([
            'tokenable_type' => self::TOKEN_TYPE, 'tokenable_id' => $sid, 'name' => 'qm-terminal',
            'token' => hash('sha256', $plain), 'abilities' => json_encode(['qm-terminal', 'tenant_id' => $tenant->id]),
            'expires_at' => now()->addHours(max(1, Qm::intSetting('terminal_sitzung_std', 12))),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Qm::t('qm_terminals')->where('id', $t->id)->update(['fehlversuche' => 0, 'letzte_anmeldung' => now()]);
        Qm::log('terminal', $t->id, 'anmeldung', null, ['geraet' => $geraet, 'ip' => $ip]);
        return [['token' => $plain, 'tenant_slug' => $tenant->slug], null];
    }

    private static function fehlversuch(object $t): void
    {
        $n = (int) $t->fehlversuche + 1;
        $max = max(1, Qm::intSetting('terminal_max_fehl', 5));
        $upd = ['fehlversuche' => $n, 'updated_at' => now()];
        if ($n >= $max) {
            $upd['gesperrt_seit'] = now();
            Qm::log('terminal', $t->id, 'gesperrt', null, ['fehlversuche' => $n]);
            $obj = Qm::t('objekte')->where('obj_id', $t->obj_id)->value('objektname') ?? $t->obj_id;
            $empf = QmNotify::objektleitung($t->obj_id);
            $titel = 'Terminal gesperrt: ' . $obj;
            $text = "Nach $n falschen PINs wurde der Terminal-Zugang {$t->kennung} gesperrt. Entsperren unter Einstellungen › Qualität › Objekt-Terminals.";
            QmNotify::users($empf, 'terminal.locked', $titel, $text, '#einstellungen?tab=qualitaet&sub=terminals', 'terminal', $t->id);
            $mails = \App\Models\CentralUser::whereIn('id', $empf)->pluck('email')->all();
            QmNotify::mail($mails, 'terminal.locked', $titel, $text, 'user', implode(',', $empf), 'terminal', $t->id);
        }
        Qm::t('qm_terminals')->where('id', $t->id)->update($upd);
    }

    /** Workers planned today for the Objekt (the "Wer bist du?" list). */
    public static function heuteGeplant(string $objId)
    {
        return Qm::t('einsatz_mitarbeiter as em')
            ->join('einsaetze as e', 'e.einsatz_id', '=', 'em.einsatz_id')
            ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'em.dienst_nr')
            ->where('e.obj_id', $objId)->where('e.datum', Qm::heute())->where('e.pruefung', 'geplant')->where('m.aktiv', 1)
            ->orderBy('e.ist_start')->orderBy('m.vorname')
            ->get(['em.dienst_nr', 'm.vorname', 'm.name', 'e.einsatz_id', 'e.ist_start', 'e.ist_ende']);
    }
}
