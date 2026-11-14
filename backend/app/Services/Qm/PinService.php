<?php

namespace App\Services\Qm;

use App\Support\Qm\Qm;
use Illuminate\Support\Facades\Hash;

/**
 * Personal 4-digit PIN of a worker (spec §5): start PIN issued by the office
 * and shown once, own PIN required on first use, no trivial PINs, locked
 * after N failures (unlock: QMB/Büro for all, Objektleiter for own objects).
 */
class PinService
{
    public static function erlaubt(string $pin): bool
    {
        if (!preg_match('/^\d{4}$/', $pin)) return false;
        if (count(array_unique(str_split($pin))) === 1) return false;       // 1111
        return !str_contains('0123456789', $pin) && !str_contains('9876543210', $pin); // 1234 / 4321
    }

    public static function status(string $dienstNr): ?object
    {
        return Qm::t('qm_mitarbeiter_pins')->where('dienst_nr', $dienstNr)->first();
    }

    /** New start PIN (old one invalid at once, terminal sessions of the worker end). Returns the plain PIN. */
    public static function startPin(string $dienstNr, $u): string
    {
        do { $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT); } while (!self::erlaubt($pin));
        Qm::t('qm_mitarbeiter_pins')->updateOrInsert(['dienst_nr' => $dienstNr], [
            'pin_hash' => Hash::make($pin), 'fehlversuche' => 0, 'gesperrt' => 0, 'start_pin' => 1,
            'geaendert_am' => now(), 'geaendert_von' => Qm::userName($u), 'updated_at' => now(), 'created_at' => now(),
        ]);
        self::sitzungenBeenden($dienstNr);
        Qm::log('mitarbeiter_pin', $dienstNr, 'start_pin', null, null, $u);
        return $pin;
    }

    /** @return array{0: bool, 1: ?string} [ok, error] — counts failures, locks at the limit. */
    public static function pruefen(string $dienstNr, string $pin, ?string $objId = null): array
    {
        $row = self::status($dienstNr);
        if (!$row) return [false, 'Für dich ist noch keine PIN vergeben. Bitte wende dich an die Objektleitung.'];
        if ($row->gesperrt) return [false, 'Deine PIN ist gesperrt. Die Objektleitung kann sie entsperren.'];
        if (Hash::check($pin, $row->pin_hash)) {
            if ($row->fehlversuche) Qm::t('qm_mitarbeiter_pins')->where('id', $row->id)->update(['fehlversuche' => 0]);
            return [true, null];
        }
        $n = (int) $row->fehlversuche + 1;
        $max = max(1, Qm::intSetting('pin_max_fehl', 3));
        $lock = $n >= $max;
        Qm::t('qm_mitarbeiter_pins')->where('id', $row->id)->update(['fehlversuche' => $n, 'gesperrt' => $lock ? 1 : 0, 'updated_at' => now()]);
        if ($lock) {
            Qm::log('mitarbeiter_pin', $dienstNr, 'gesperrt', null, ['fehlversuche' => $n]);
            self::sitzungenBeenden($dienstNr);
            $empf = $objId ? QmNotify::objektleitung($objId) : QmNotify::rollenUser(['qmb']);
            QmNotify::users($empf, 'pin.locked', 'Mitarbeiter-PIN gesperrt: ' . Qm::workerName($dienstNr),
                "Nach $n Fehlversuchen gesperrt. Entsperren unter Einstellungen › Qualität › Mitarbeiter-PIN.", '#einstellungen?tab=qualitaet&sub=pins', 'mitarbeiter_pin', $dienstNr);
            return [false, 'PIN falsch — deine PIN ist jetzt gesperrt. Bitte wende dich an die Objektleitung.'];
        }
        return [false, 'PIN falsch. Noch ' . ($max - $n) . ' Versuch' . ($max - $n === 1 ? '' : 'e') . '.'];
    }

    /** Set an own PIN (after the start PIN or with the old one). @return string|null error */
    public static function setzen(string $dienstNr, string $neu, string $quelle): ?string
    {
        if (!self::erlaubt($neu)) return 'Die PIN muss aus 4 Ziffern bestehen; gleiche Ziffern (1111) und Folgen (1234, 4321) sind nicht erlaubt.';
        Qm::t('qm_mitarbeiter_pins')->where('dienst_nr', $dienstNr)->update([
            'pin_hash' => Hash::make($neu), 'start_pin' => 0, 'fehlversuche' => 0,
            'geaendert_am' => now(), 'geaendert_von' => Qm::workerName($dienstNr) . ' (' . $quelle . ')', 'updated_at' => now(),
        ]);
        Qm::log('mitarbeiter_pin', $dienstNr, 'geaendert', null, ['quelle' => $quelle]);
        return null;
    }

    public static function entsperren(string $dienstNr, $u): void
    {
        Qm::t('qm_mitarbeiter_pins')->where('dienst_nr', $dienstNr)->update(['gesperrt' => 0, 'fehlversuche' => 0, 'updated_at' => now()]);
        Qm::log('mitarbeiter_pin', $dienstNr, 'entsperrt', null, null, $u);
    }

    public static function sperren(string $dienstNr, $u): void
    {
        Qm::t('qm_mitarbeiter_pins')->where('dienst_nr', $dienstNr)->update(['gesperrt' => 1, 'updated_at' => now()]);
        self::sitzungenBeenden($dienstNr);
        Qm::log('mitarbeiter_pin', $dienstNr, 'gesperrt', null, null, $u);
    }

    /** Sign the worker out of every terminal (session itself stays open). */
    public static function sitzungenBeenden(string $dienstNr): void
    {
        Qm::t('qm_terminal_sitzungen')->where('dienst_nr', $dienstNr)->whereNull('ende')
            ->update(['dienst_nr' => null, 'wachperson_seit' => null]);
    }
}
