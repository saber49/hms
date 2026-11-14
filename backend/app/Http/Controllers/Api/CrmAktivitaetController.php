<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Crm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Lead-Modul — Aktivitäten (spec 2.5, dialog 5.3) and linked Aufgaben (2.6).
 * Logging on a lead runs A4/A5. A call on a lead needs a follow-up task
 * unless the user qualifies / disqualifies next (spec 5.3).
 */
class CrmAktivitaetController extends Controller
{
    private const TYPEN = ['anruf', 'email', 'termin', 'besichtigung', 'notiz'];

    /** [record, kontakt_id] if the actor may write on it, else a JSON error response. */
    private function bezug($u, string $typ, string $id)
    {
        if ($typ === 'lead') {
            $r = Crm::lead($u, $id);
            if (!$r) return response()->json(['error' => 'Lead nicht gefunden'], 404);
        } elseif ($typ === 'deal') {
            $r = Crm::deal($u, $id);
            if (!$r) return response()->json(['error' => 'Deal nicht gefunden'], 404);
        } elseif ($typ === 'kontakt') {
            $r = Crm::t('crm_kontakte')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$r) return response()->json(['error' => 'Kontakt nicht gefunden'], 404);
        } else {
            return Crm::fehler(['bezug_typ' => ['Ungültiger Bezug.']]);
        }
        $darf = $typ === 'kontakt' ? Crm::can($u, 'kontakte_edit') : Crm::darfBearbeiten($u, $r, $typ === 'lead' ? 'leads' : 'deals');
        if (!$darf) return response()->json(['error' => 'Keine Berechtigung'], 403);
        return [$r, $typ === 'kontakt' ? $r->id : $r->kontakt_id];
    }

    /** POST /crm/aktivitaeten (JSON or multipart with `datei`). */
    public function store(Request $request)
    {
        if (!Crm::ready()) return response()->json(['error' => 'Lead-Modul noch nicht eingerichtet'], 409);
        $u = $request->user();
        $in = $request->all();
        if (is_string($in['aufgabe'] ?? null)) $in['aufgabe'] = json_decode($in['aufgabe'], true) ?: null;

        [$d, $err] = Crm::pruefe($in, [
            'bezug_typ' => 'required|in:lead,kontakt,deal', 'bezug_id' => 'required|uuid',
            'typ' => 'required|in:' . implode(',', self::TYPEN),
            'richtung' => 'nullable|in:ausgehend,eingehend', 'ergebnis' => 'nullable|string|max:60',
            'notiz' => 'nullable|string|max:5000', 'zeitpunkt' => 'nullable|date',
            'folge' => 'nullable|in:aufgabe,qualifizieren,disqualifizieren,keine',
            'aufgabe' => 'nullable|array', 'aufgabe.typ' => 'nullable|string|max:30', 'aufgabe.titel' => 'nullable|string|max:200',
            'aufgabe.faellig_am' => 'nullable|date_format:Y-m-d', 'aufgabe.faellig_zeit' => 'nullable|date_format:H:i',
            'datei' => 'nullable|' . Crm::DATEI_REGEL,
        ]);
        if ($err) return Crm::fehler($err);

        $b = $this->bezug($u, $d['bezug_typ'], $d['bezug_id']);
        if (!is_array($b)) return $b;
        [$rec, $kontaktId] = $b;

        $err = [];
        $istKontakt = in_array($d['typ'], ['anruf', 'email'], true);
        if ($istKontakt && empty($d['richtung'])) $err['richtung'] = ['Richtung ist bei Anruf/E-Mail Pflicht.'];
        if ($d['typ'] === 'anruf' && !Crm::gueltig('anruf_ergebnis', $d['ergebnis'] ?? null)) $err['ergebnis'] = ['Ergebnis ist bei einem Anruf Pflicht.'];
        if ($d['typ'] === 'notiz' && trim((string) ($d['notiz'] ?? '')) === '') $err['notiz'] = ['Notiz ist Pflicht.'];

        // Spec 5.3: a call on an open lead needs the next step as a task.
        $folge = $d['folge'] ?? null;
        $leadOffen = $d['bezug_typ'] === 'lead' && in_array($rec->stage, Crm::LEAD_OFFEN, true);
        if ($leadOffen && $d['typ'] === 'anruf' && !in_array($folge, ['aufgabe', 'qualifizieren', 'disqualifizieren'], true)) {
            $err['folge'] = ['Nächster Schritt als Aufgabe ist Pflicht (außer bei Qualifizieren/Disqualifizieren).'];
        }
        $a = $d['aufgabe'] ?? [];
        if ($folge === 'aufgabe') {
            if (empty($a['faellig_am'])) $err['aufgabe.faellig_am'] = ['Datum der Aufgabe ist Pflicht.'];
            if (trim((string) ($a['titel'] ?? '')) === '') $err['aufgabe.titel'] = ['Titel der Aufgabe ist Pflicht.'];
            if (!empty($a['typ']) && !Crm::gueltig('aufgabe_typ', $a['typ'])) $err['aufgabe.typ'] = ['Aufgabentyp: ungültiger Wert.'];
        }
        if ($err) return Crm::fehler($err);

        $datei = $request->hasFile('datei') ? Crm::datei($request->file('datei'), 'aktivitaeten') : [null, null, null];
        $zeit = !empty($d['zeitpunkt']) ? \Carbon\Carbon::parse($d['zeitpunkt'], 'Europe/Berlin')->utc() : now();
        if ($zeit->gt(now()->addMinutes(5))) {
            if ($datei[1]) Storage::disk('local')->delete($datei[1]);
            return Crm::fehler(['zeitpunkt' => ['Zeitpunkt darf nicht in der Zukunft liegen — dafür eine Aufgabe anlegen.']]);
        }

        $id = DB::connection('tenant')->transaction(function () use ($u, $d, $rec, $kontaktId, $datei, $zeit, $folge, $a, $istKontakt) {
            $row = [
                'kontakt_id' => $kontaktId,
                'lead_id' => $d['bezug_typ'] === 'lead' ? $rec->id : null,
                'deal_id' => $d['bezug_typ'] === 'deal' ? $rec->id : null,
                'typ' => $d['typ'], 'richtung' => $istKontakt ? $d['richtung'] : null,
                'ergebnis' => $d['typ'] === 'anruf' ? $d['ergebnis'] : null,
                'zeitpunkt' => $zeit->format('Y-m-d H:i:s'), 'user_id' => $u->id,
                'notiz' => trim((string) ($d['notiz'] ?? '')) ?: null,
                'datei_disk' => $datei[0], 'datei_pfad' => $datei[1], 'datei_name' => $datei[2],
                'created_at' => now(), 'updated_at' => now(),
            ];
            $id = Crm::t('crm_aktivitaeten')->insertGetId($row);
            if ($d['bezug_typ'] === 'lead') Crm::nachAktivitaet($rec, (object) $row, $u);
            if ($d['bezug_typ'] === 'deal') Crm::t('crm_deals')->where('id', $rec->id)->update(['updated_at' => now()]);
            if ($folge === 'aufgabe') {
                Crm::aufgabe($d['bezug_typ'], $rec->id, $a['typ'] ?? 'anruf', trim($a['titel']), $a['faellig_am'], $a['faellig_zeit'] ?? null,
                    (int) ($rec->owner_id ?? $u->id), $u, $rec->sparte ?? null);
            }
            return $id;
        });

        Crm::changed();
        $stage = $d['bezug_typ'] === 'lead' ? Crm::t('crm_leads')->where('id', $rec->id)->value('stage') : null;
        return response()->json(['id' => $id, 'stage' => $stage], 201);
    }

    /** GET /crm/aktivitaeten/{id}/datei — same visibility as the linked record. */
    public function datei(Request $request, int $id)
    {
        if (!Crm::ready()) return response()->json(['error' => 'Datei nicht gefunden'], 404);
        $u = $request->user();
        $a = Crm::t('crm_aktivitaeten')->where('id', $id)->first();
        if (!$a || !$a->datei_pfad) return response()->json(['error' => 'Datei nicht gefunden'], 404);
        $sichtbar = ($a->lead_id && Crm::lead($u, $a->lead_id)) || ($a->deal_id && Crm::deal($u, $a->deal_id))
            || (!$a->lead_id && !$a->deal_id && $a->kontakt_id);
        $disk = $a->datei_disk ?: 'public';
        if (!$sichtbar || !Storage::disk($disk)->exists($a->datei_pfad)) return response()->json(['error' => 'Datei nicht gefunden'], 404);
        return response()->download(Storage::disk($disk)->path($a->datei_pfad), $a->datei_name ?: basename($a->datei_pfad),
            ['Cache-Control' => 'no-store']);
    }

    /** POST /crm/aufgaben {bezug_typ, bezug_id, typ, titel, faellig_am, faellig_zeit?, zugewiesen_an?, beschreibung?} */
    public function aufgabe(Request $request)
    {
        if (!Crm::ready()) return response()->json(['error' => 'Lead-Modul noch nicht eingerichtet'], 409);
        $u = $request->user();
        [$d, $err] = Crm::pruefe($request->all(), [
            'bezug_typ' => 'required|in:lead,kontakt,deal', 'bezug_id' => 'required|uuid',
            'typ' => 'required|string|max:30', 'titel' => 'required|string|max:200',
            'faellig_am' => 'required|date_format:Y-m-d', 'faellig_zeit' => 'nullable|date_format:H:i',
            'zugewiesen_an' => 'nullable|integer', 'beschreibung' => 'nullable|string|max:5000',
        ]);
        if ($err) return Crm::fehler($err);
        if (!Crm::gueltig('aufgabe_typ', $d['typ'])) return Crm::fehler(['typ' => ['Aufgabentyp: ungültiger Wert.']]);
        $b = $this->bezug($u, $d['bezug_typ'], $d['bezug_id']);
        if (!is_array($b)) return $b;
        [$rec] = $b;
        $an = (int) ($d['zugewiesen_an'] ?? ($rec->owner_id ?? $u->id));
        if (!Crm::ownerGueltig($u, $an)) return Crm::fehler(['zugewiesen_an' => ['Ungültiger Benutzer.']]);
        $id = Crm::aufgabe($d['bezug_typ'], $rec->id, $d['typ'], $d['titel'], $d['faellig_am'], $d['faellig_zeit'] ?? null,
            $an, $u, $rec->sparte ?? null, $d['beschreibung'] ?? null);
        Crm::changed();
        return response()->json(['id' => $id], 201);
    }
}
