<?php

namespace App\Services\Qm;

use App\Helpers\NotificationDispatcher;
use App\Mail\PlainNotificationMail;
use App\Models\CentralUser;
use App\Services\TenantService;
use App\Support\Qm\Qm;
use App\Support\Qm\QmRechte;
use Illuminate\Support\Facades\Mail;

/**
 * Every QM notification goes through here: office users (bell + app push via
 * NotificationDispatcher), workers (worker_notifications + push), customers
 * (e-mail). Each delivery is written to qm_benachrichtigungen (protocol page,
 * ISO 9001 7.4). Best effort — never breaks the request.
 */
class QmNotify
{
    public static function users(array $ids, string $ereignis, string $titel, string $text, string $link, ?string $bezugTyp = null, $bezugId = null): void
    {
        try {
            $tenant = TenantService::current();
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if (!$tenant || !$ids) return;
            NotificationDispatcher::notifyUserIds((int) $tenant->id, $ids, 'qm.' . $ereignis, $titel, $text, $link);
            foreach ($ids as $id) self::protokoll($ereignis, 'user', $id, 'app', $titel, $text, $bezugTyp, $bezugId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function worker(string $dienstNr, string $ereignis, string $titel, string $text, array $payload = [], ?string $bezugTyp = null, $bezugId = null): void
    {
        try {
            $w = Qm::t('mitarbeiter')->where('dienst_nr', $dienstNr)->where('aktiv', 1)->first();
            if (!$w) return;
            NotificationDispatcher::notifyWorker($w, 'qm_' . $ereignis, $titel, $text, $payload);
            self::protokoll($ereignis, 'mitarbeiter', $dienstNr, 'push', $titel, $text, $bezugTyp, $bezugId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return int number of addresses the mail was queued for (0 = nothing sent) */
    public static function mail(array $to, string $ereignis, string $titel, string $text, string $empfTyp = 'kunde', $empfId = '', ?string $bezugTyp = null, $bezugId = null): int
    {
        try {
            $to = array_values(array_unique(array_filter(array_map('trim', $to), fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL))));
            if (!$to) return 0;
            $tenant = TenantService::current();
            $mail = new PlainNotificationMail($titel . ($tenant ? ' | ' . $tenant->name : ''), $text);
            $from = trim((string) Qm::setting('absender_email', ''));
            if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) $mail->replyTo($from);
            Mail::to($to)->queue($mail);
            self::protokoll($ereignis, $empfTyp, $empfId ?: implode(',', $to), 'email', $titel, $text, $bezugTyp, $bezugId);
            return count($to);
        } catch (\Throwable $e) {
            report($e);
            return 0;
        }
    }

    /** Was this event already delivered for the record? (reminders / escalations run once) */
    public static function schonGesendet(string $ereignis, string $bezugTyp, $bezugId): bool
    {
        return Qm::t('qm_benachrichtigungen')->where('ereignis', $ereignis)->where('bezug_typ', $bezugTyp)
            ->where('bezug_id', (string) $bezugId)->exists();
    }

    /** Marker for events without a recipient, so they are not retried every run. */
    public static function merke(string $ereignis, string $bezugTyp, $bezugId, string $titel): void
    {
        self::protokoll($ereignis, 'system', '-', 'intern', $titel, '', $bezugTyp, $bezugId);
    }

    /** Office users holding one of the QM roles (optionally only with one action right). */
    public static function rollenUser(array $rollen, ?string $aktion = null): array
    {
        $tenant = TenantService::current();
        if (!$tenant) return [];
        return CentralUser::where('tenant_id', $tenant->id)->where('aktiv', 1)->get()
            ->filter(fn ($u) => array_intersect(QmRechte::rollen($u), $rollen) && (!$aktion || QmRechte::darf($u, $aktion)))
            ->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
    }

    /** Objektleiter of an Objekt, else every QMB. */
    public static function objektleitung(string $objId): array
    {
        $ol = Qm::objektEinstellung($objId)->objektleiter_id;
        return $ol ? [(int) $ol] : self::rollenUser(['qmb']);
    }

    // ---------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------

    public static function vorkommnis(object $e): void
    {
        $obj = Qm::t('objekte')->where('obj_id', $e->obj_id)->value('objektname') ?? $e->obj_id;
        $art = $e->art === 'alarm' ? 'Alarm' : 'Vorkommnis';
        self::users(self::objektleitung($e->obj_id), 'wachbuch.vorkommnis', "$art im Wachbuch: $obj",
            mb_substr((string) $e->text, 0, 200) . ($e->erfasst_von ? ' — ' . $e->erfasst_von : ''),
            '#qm_wachbuch', 'wachbuch_eintrag', $e->id);
    }

    private static function protokoll(string $ereignis, string $typ, $id, string $kanal, string $titel, string $text, ?string $bezugTyp, $bezugId): void
    {
        Qm::t('qm_benachrichtigungen')->insert([
            'ereignis' => mb_substr($ereignis, 0, 60), 'empfaenger_typ' => $typ, 'empfaenger_id' => (string) $id,
            'kanal' => $kanal, 'titel' => mb_substr($titel, 0, 255), 'text' => $text,
            'bezug_typ' => $bezugTyp, 'bezug_id' => $bezugId === null ? null : (string) $bezugId, 'gesendet_am' => now(),
        ]);
    }
}
