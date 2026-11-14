<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CentralUser;
use App\Models\Tenant;
use App\Services\TenantService;
use App\Support\BereichScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Auftragsbestätigungen — commercial order confirmations sent to the
 * customer after an order has been assembled in Bestelleingang.
 *
 * Positions follow the tenant's own pricing model (artikel.modell or the
 * per-order override stored in bestelleingang.artikel_preise):
 *   'Pro Stunde'      → qty = Σ(soll_std × menge) of that service's days  [Einheit: Std.]
 *   'Pro Einsatz'     → qty = number of that service's days               [Einheit: Eins.]
 *   'Monatspauschale' → qty = 1                                           [Einheit: Pausch.]
 *   anything else     → qty = number of that service's days (≥1)          [Einheit: artikel.einheit ?? 'Eins.']
 *
 * The PDF is rendered with the tenant's default pdf_template settings so
 * it matches the branding configured under Dokument-Design.
 */
class OrderConfirmationController extends Controller
{
    private const UST_RATE = 19.0;

    private const MONTHS_DE = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
        5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    /**
     * GET /api/order-confirmations
     * Lists every assembled Bestelleingang with its services, day count
     * and the computed netto total (same math the PDF uses), so the grid
     * always previews what the document will bill.
     *
     * Filters: kunden_id, obj_id, monat+jahr, status (bereit|versendet), search.
     */
    public function index(Request $request)
    {
        $hasArtikelIds = Schema::connection('tenant')->hasColumn('bestelleingang', 'artikel_ids');
        $hasSentCol    = Schema::connection('tenant')->hasColumn('bestelleingang', 'ab_sent_at');

        $query = DB::connection('tenant')
            ->table('bestelleingang as be')
            ->leftJoin('kunden as k', 'be.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'be.obj_id', '=', 'o.obj_id')
            ->leftJoin('artikel as a', 'be.artikel_id', '=', 'a.artikel_id')
            ->select(
                'be.eingang_id', 'be.kunden_id', 'be.obj_id', 'be.artikel_id',
                'be.monat', 'be.jahr', 'be.status', 'be.kundenpreis', 'be.modell',
                'be.created_at',
                'k.kundenname', 'k.bereich as kunden_bereich',
                'o.objektname',
                'a.artikel_name', 'a.bereich as artikel_bereich',
                DB::raw("(SELECT b.bestell_id FROM bestellungen b WHERE b.eingang_id = be.eingang_id ORDER BY b.bestell_id LIMIT 1) as bestell_id")
            );
        if ($hasArtikelIds) {
            $query->addSelect('be.artikel_ids', 'be.artikel_preise');
            $query->addSelect(DB::raw(
                "(SELECT GROUP_CONCAT(a2.artikel_name SEPARATOR ' · ') FROM artikel a2
                  WHERE JSON_CONTAINS(be.artikel_ids, JSON_QUOTE(a2.artikel_id))) as artikel_namen"
            ));
        }
        if ($hasSentCol) {
            $query->addSelect('be.ab_sent_at', 'be.ab_sent_by');
        }
        // Content-hash inputs (same as buildConfirmationData) + version.
        $query->addSelect('be.zeit_von', 'be.zeit_bis');
        if (Schema::connection('tenant')->hasColumn('bestelleingang', 'ab_bemerkung')) {
            $query->addSelect('be.ab_bemerkung');
        }
        $hasVersions = $this->hasVersions();
        foreach (['created_by', 'ab_bearbeiter_id'] as $c) {
            if (Schema::connection('tenant')->hasColumn('bestelleingang', $c)) $query->addSelect('be.' . $c);
        }
        if (Schema::connection('tenant')->hasColumn('kunden', 'betreuer_id')) $query->addSelect('k.betreuer_id as kunde_betreuer_id');
        if ($hasVersions) $query->addSelect('be.ab_version');

        if ($request->filled('kunden_id')) $query->where('be.kunden_id', $request->kunden_id);
        if ($request->filled('obj_id'))    $query->where('be.obj_id', $request->obj_id);
        if ($request->filled('monat') && $request->filled('jahr')) {
            $query->where('be.monat', $request->monat)->where('be.jahr', $request->jahr);
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s, $hasArtikelIds) {
                $q->where('be.eingang_id', 'like', $s)
                  ->orWhere('k.kundenname', 'like', $s)
                  ->orWhere('o.objektname', 'like', $s)
                  ->orWhere('a.artikel_name', 'like', $s)
                  ->orWhereExists(function ($x) use ($s) {
                      $x->selectRaw('1')->from('bestellungen as b')
                        ->whereColumn('b.eingang_id', 'be.eingang_id')
                        ->where('b.bestell_id', 'like', $s);
                  });
                if ($hasArtikelIds) {
                    $q->orWhereExists(function ($x) use ($s) {
                        $x->selectRaw('1')->from('artikel as a3')
                          ->whereRaw('JSON_CONTAINS(be.artikel_ids, JSON_QUOTE(a3.artikel_id))')
                          ->where('a3.artikel_name', 'like', $s);
                    });
                }
            });
        }

        // Sent-status filter: 'versendet' = ab_sent_at set, 'bereit' = not.
        if ($hasSentCol && $request->filled('ab_status')) {
            if ($request->ab_status === 'versendet') {
                $query->whereNotNull('be.ab_sent_at');
            } elseif ($request->ab_status === 'bereit') {
                $query->whereNull('be.ab_sent_at');
            }
        }

        // Per-user Bereich restriction — identical semantics to
        // IncomingOrderController::index (primary artikel OR any selected
        // artikel of a multi-service order may carry the order into scope).
        $allowedBe = BereichScope::for($request->user());
        if ($allowedBe !== null) {
            $mainExpr = "COALESCE(NULLIF(TRIM(a.bereich), ''), NULLIF(TRIM(k.bereich), ''))";
            if (empty($allowedBe)) {
                $query->whereRaw('1 = 0');
            } else {
                [$mainSql, $mainP] = BereichScope::inList($allowedBe, $mainExpr);
                if ($hasArtikelIds) {
                    $subExpr = "COALESCE(NULLIF(TRIM(_ax2.bereich), ''), NULLIF(TRIM(k.bereich), ''))";
                    [$subSql, $subP] = BereichScope::inList($allowedBe, $subExpr);
                    $query->whereRaw(
                        "($mainSql OR EXISTS (SELECT 1 FROM artikel _ax2
                            WHERE JSON_CONTAINS(be.artikel_ids, JSON_QUOTE(_ax2.artikel_id))
                              AND $subSql))",
                        array_merge($mainP, $subP)
                    );
                } else {
                    $query->whereRaw($mainSql, $mainP);
                }
            }
        }

        $limit = min(intval($request->query('limit', 500)), 1000);
        $rows = $query->orderByDesc('be.id')->limit($limit)->get();
        if ($rows->isEmpty()) return response()->json([]);

        // Bulk-prefetch everything the position math needs — two queries
        // total regardless of how many orders the page shows.
        $eingangIds = $rows->pluck('eingang_id')->all();
        $tageByEingang = DB::connection('tenant')->table('bestelleingang_tage')
            ->whereIn('eingang_id', $eingangIds)
            ->get(['eingang_id', 'artikel_id', 'soll_std', 'menge', 'datum'])
            ->groupBy('eingang_id');

        $allArtikelIds = [];
        foreach ($rows as $r) {
            foreach ($this->artikelIdsOf($r) as $aid) $allArtikelIds[$aid] = true;
        }
        $artikelMap = !empty($allArtikelIds)
            ? DB::connection('tenant')->table('artikel')
                ->whereIn('artikel_id', array_keys($allArtikelIds))
                ->get()->keyBy('artikel_id')
            : collect();

        // Hash of every currently sent version (one query) → changed flag.
        $sentHashes = [];
        if ($hasVersions) {
            $pairs = $rows->filter(fn ($r) => !empty($r->ab_version))->map(fn ($r) => [$r->eingang_id, (int) $r->ab_version]);
            if ($pairs->isNotEmpty()) {
                $q = DB::connection('tenant')->table('auftragsbestaetigung_versionen')->where(function ($w) use ($pairs) {
                    foreach ($pairs as [$e, $v]) $w->orWhere(fn ($x) => $x->where('eingang_id', $e)->where('version', $v));
                });
                foreach ($q->get(['eingang_id', 'content_hash']) as $h) $sentHashes[$h->eingang_id] = $h->content_hash;
            }
        }

        $out = $rows->map(function ($r) use ($tageByEingang, $artikelMap, $hasSentCol, $sentHashes) {
            $tage = $tageByEingang->get($r->eingang_id, collect());
            $calc = $this->computePositions(
                $this->artikelIdsOf($r),
                $this->preiseOf($r),
                $tage,
                $artikelMap,
                ['modell' => $r->modell, 'kundenpreis' => $r->kundenpreis]
            );
            $version = (int) ($r->ab_version ?? 0);
            $changed = false;
            if ($version && isset($sentHashes[$r->eingang_id])) {
                $leistungszeit = ($r->zeit_von || $r->zeit_bis)
                    ? substr((string) $r->zeit_von, 0, 5) . ' – ' . substr((string) $r->zeit_bis, 0, 5) . ' Uhr' : null;
                $zeitraum = (self::MONTHS_DE[(int) $r->monat] ?? $r->monat) . ' ' . $r->jahr;
                $ref = $this->bearbeiterRef($r);
                $changed = $sentHashes[$r->eingang_id] !== $this->contentHash($calc['positions'], $calc['totals'], $zeitraum, $leistungszeit, $ref, $r->ab_bemerkung ?? null);
            }
            return [
                'ab_version'         => $version ?: null,
                'changed_since_sent' => $changed,
                'eingang_id'     => $r->eingang_id,
                'bestell_id'     => $r->bestell_id,
                'kunden_id'      => $r->kunden_id,
                'kundenname'     => $r->kundenname,
                'objektname'     => $r->objektname,
                'monat'          => (int) $r->monat,
                'jahr'           => (int) $r->jahr,
                'status'         => $r->status,
                'leistungen'     => $r->artikel_namen ?? $r->artikel_name,
                'positions'      => count($calc['positions']),
                'tage_count'     => $tage->count(),
                // Planned hours (Σ soll_std × menge) — the list shows hours, no prices.
                'stunden'        => round(array_sum(array_column($calc['positions'], 'stunden')), 2),
                'netto'          => $calc['totals']['netto'],
                'brutto'         => $calc['totals']['brutto'],
                'ab_sent_at'     => $hasSentCol ? ($r->ab_sent_at ?? null) : null,
                'ab_sent_by'     => $hasSentCol ? ($r->ab_sent_by ?? null) : null,
                'created_at'     => $r->created_at,
            ];
        });

        // Resolve sent-by names from central_users (same tenant).
        $sentByIds = $out->pluck('ab_sent_by')->filter()->unique()->values()->all();
        $names = [];
        if (!empty($sentByIds) && $request->user()) {
            $names = DB::connection('mysql')->table('central_users')
                ->where('tenant_id', (int) $request->user()->tenant_id)
                ->whereIn('id', $sentByIds)
                ->whereRaw("name NOT LIKE 'deleted\\_%'")
                ->pluck('full_name', 'id')
                ->all();
        }
        $out->transform(function ($r) use ($names) {
            $r['ab_sent_by_name'] = $r['ab_sent_by'] ? ($names[$r['ab_sent_by']] ?? null) : null;
            return $r;
        });

        return response()->json($out->values());
    }

    /**
     * GET /api/order-confirmations/{id}
     * Full document payload for the preview modal (positions + totals +
     * address blocks) — identical to what the PDF renders.
     */
    public function show(Request $request, string $id)
    {
        $data = $this->buildConfirmationData($id, $request->user());
        if (!$data) {
            return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        }
        $be = $data['eingang'];
        $versions = $this->versionsOf($id, $request->user());
        $sentVersion = (int) ($be->ab_version ?? 0);

        // Sent → the frozen document exactly as it went to the customer
        // (date, Bearbeiter, positions…), unless ?live=1 (used to render a
        // NEW version). changed_since_sent flags edits made after sending.
        if (!empty($be->ab_sent_at) && $sentVersion && !$request->boolean('live')) {
            $snap = DB::connection('tenant')->table('auftragsbestaetigung_versionen')
                ->where('eingang_id', $id)->where('version', $sentVersion)->first();
            $frozen = $snap ? json_decode((string) $snap->data, true) : null;
            if (is_array($frozen)) {
                return response()->json(array_merge($frozen, [
                    'frozen'             => true,
                    'version'            => $sentVersion,
                    'changed_since_sent' => $snap->content_hash !== $data['content_hash'],
                    'versions'           => $versions,
                    'ab_sent_at'         => $be->ab_sent_at,
                    'ab_sent_by_name'    => $data['ab_sent_by_name'] ?? null,
                    'bearbeiter_id'      => $data['bearbeiter']['id'],
                    'bearbeiter_chosen'  => $data['bearbeiter']['chosen'],
                    'bearbeiter_source'  => $data['bearbeiter']['source'],
                    'bemerkungen_live'   => $data['bemerkungen'],
                ]));
            }
        }

        $next = ($versions[0]['version'] ?? 0) + 1;
        $payload = $this->documentPayload($data);
        $payload['meta']['version'] = $next;
        return response()->json(array_merge($payload, [
            'frozen'             => false,
            'next_version'       => $next,
            'content_hash'       => $data['content_hash'],
            'changed_since_sent' => !empty($be->ab_sent_at) && $sentVersion
                && (($versions[0]['content_hash'] ?? null) !== $data['content_hash']),
            'versions'           => $versions,
            'ab_sent_at'         => $be->ab_sent_at ?? null,
            'ab_sent_by_name'    => $data['ab_sent_by_name'] ?? null,
            'bearbeiter_id'      => $data['bearbeiter']['id'],
            'bearbeiter_chosen'  => $data['bearbeiter']['chosen'],
            'bearbeiter_source'  => $data['bearbeiter']['source'],
            'bemerkungen_live'   => $data['bemerkungen'],
        ]));
    }

    /** The document part of the show() payload (also what gets frozen). */
    protected function documentPayload(array $data): array
    {
        return [
            'eingang_id'    => $data['eingang']->eingang_id,
            'bestell_id'    => $data['bestellung']->bestell_id ?? null,
            'dokument_nr'   => $data['dokument_nr'],
            'meta'          => $data['meta'],
            'kunde'         => $data['kunde'],
            'lieferadresse' => $data['lieferadresse'],
            'positions'     => $data['positions'],
            'totals'        => $data['totals'],
            'bemerkungen'   => $data['bemerkungen'],
        ];
    }

    /**
     * GET /api/order-confirmation-bearbeiter
     * Active users of the company that can be chosen as "Bearbeiter".
     */
    public function bearbeiterOptions(Request $request)
    {
        $u = $request->user();
        $rows = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', (int) $u->tenant_id)->where('aktiv', 1)
            ->whereRaw("name NOT LIKE 'deleted\\_%'")->where('name', '!=', 'demo-viewer')
            ->orderBy('full_name')->get(['id', 'full_name', 'name', 'email']);
        return response()->json($rows->map(fn ($r) => [
            'id'   => (int) $r->id,
            'name' => trim((string) $r->full_name) !== '' ? $r->full_name : ($r->name ?: $r->email),
        ])->values());
    }

    /**
     * PUT /api/order-confirmations/{id}/bearbeiter  { user_id | null }
     * Sets the responsible person (null = back to the creator). A sent
     * version stays frozen — the change shows up as "geändert seit Versand".
     */
    public function setBearbeiter(Request $request, string $id)
    {
        if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'ab_bearbeiter_id')) {
            return response()->json(['error' => 'Funktion noch nicht aktiv (tenant:migrate ausstehend)'], 503);
        }
        $exists = DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->exists();
        if (!$exists || !BereichScope::allowsEingang($request->user(), $id)) {
            return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        }
        $uid = $request->input('user_id');
        if ($uid !== null && $uid !== '') {
            $ok = DB::connection('mysql')->table('central_users')
                ->where('tenant_id', (int) $request->user()->tenant_id)->where('id', (int) $uid)
                ->where('aktiv', 1)->whereRaw("name NOT LIKE 'deleted\\_%'")->exists();
            if (!$ok) return response()->json(['error' => 'Benutzer nicht gefunden'], 422);
            $uid = (int) $uid;
        } else {
            $uid = null;
        }
        DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->update(['ab_bearbeiter_id' => $uid]);
        \App\Helpers\Realtime::changed('eingang');
        return response()->json(['success' => true]);
    }

    /**
     * PUT /api/order-confirmations/{id}/bemerkung  { text }
     * Own "Bemerkungen" of the Auftragsbestätigung. Empty or equal to the
     * automatic notes → back to automatic (NULL). A sent version stays
     * frozen — a change shows up as "geändert seit Versand".
     */
    public function setBemerkung(Request $request, string $id)
    {
        if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'ab_bemerkung')) {
            return response()->json(['error' => 'Funktion noch nicht aktiv (tenant:migrate ausstehend)'], 503);
        }
        $data = $this->buildConfirmationData($id, $request->user());
        if (!$data) return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        $text = str_replace("\r\n", "\n", trim((string) $request->input('text', '')));
        if (mb_strlen($text) > 5000) return response()->json(['error' => 'Maximal 5000 Zeichen'], 422);
        $auto = implode("\n\n", $data['bemerkungen_auto']);
        $value = ($text === '' || $text === $auto) ? null : $text;
        DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->update(['ab_bemerkung' => $value]);
        \App\Helpers\Realtime::changed('eingang');
        return response()->json(['success' => true, 'automatic' => $value === null]);
    }

    /**
     * GET /api/order-confirmations/{id}/archive?version=n
     * The archived PDF exactly as sent (default: the currently sent version).
     */
    public function archivePdf(Request $request, string $id)
    {
        $user = $request->user();
        if (!$this->hasVersions() || !BereichScope::allowsEingang($user, $id)) {
            return response()->json(['error' => 'Nicht gefunden'], 404);
        }
        return $this->streamArchive((int) $user->tenant_id, $id, (int) $request->query('version', 0), $request->boolean('download'));
    }

    protected function streamArchive(int $tenantId, string $id, int $version, bool $download)
    {
        if ($version <= 0) {
            $version = (int) DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->value('ab_version');
        }
        $row = $version > 0 ? DB::connection('tenant')->table('auftragsbestaetigung_versionen')
            ->where('eingang_id', $id)->where('version', $version)->first() : null;
        if (!$row || !Storage::disk('local')->exists($row->pdf_path)) {
            return response()->json(['error' => 'Archivierte Version nicht gefunden'], 404);
        }
        $name = 'Auftragsbestaetigung_' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $id) . ($version > 1 ? '_V' . $version : '') . '.pdf';
        return response(Storage::disk('local')->get($row->pdf_path), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * GET /api/order-confirmations/{id}/pdf
     * Renders the Auftragsbestätigung with the tenant's default
     * pdf_template settings + logo. ?action=download forces a download,
     * ?settings= accepts a JSON override (same convention as order PDFs).
     */
    public function pdf(Request $request, string $id)
    {
        return $this->renderPdf($request, $id, $request->user(), $request->input('action') === 'download');
    }

    /**
     * GET /api/order-confirmations/{id}/pdf-link?download=1
     * Short-lived signed URL for phones / the app WebView, which can't
     * attach a Bearer header to a plain navigation (and can't show blob:
     * PDFs). The signature binds tenant + user + order + mode; the scope
     * is re-checked when the link is opened.
     */
    public function pdfLink(Request $request, string $id)
    {
        $user = $request->user();
        $exists = DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->exists();
        if (!$exists || !BereichScope::allowsEingang($user, $id)) {
            return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        }

        $params = [
            't'  => (int) $user->tenant_id,
            'u'  => (int) $user->id,
            'id' => $id,
            'dl' => $request->boolean('download') ? 1 : 0,
        ];
        // ?archive=1 → the archived (sent) version instead of a fresh render.
        if ($request->boolean('archive') && $this->hasVersions()) {
            $v = (int) DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->value('ab_version');
            if ($v > 0) $params['arc'] = $v;
        }
        $url = URL::temporarySignedRoute('order-confirmations.signed-pdf', now()->addMinutes(10), $params);

        return response()->json(['url' => $url, 'expires_in' => 600]);
    }

    /**
     * POST /api/order-confirmations/{id}/pdf-link (multipart `pdf`)
     * The editor renders the Auftragsbestätigung client-side with the
     * company layout template. Phones / the app WebView still need a
     * plain URL to hand to the system viewer, so the rendered binary is
     * parked on the private disk for 10 minutes and served through the
     * same signed route (re-validated there like the server render).
     */
    public function pdfLinkUpload(Request $request, string $id)
    {
        $user = $request->user();
        $exists = DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->exists();
        if (!$exists || !BereichScope::allowsEingang($user, $id)) {
            return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        }
        $request->validate(['pdf' => 'required|file|mimetypes:application/pdf|max:15360']);

        $token = bin2hex(random_bytes(16));
        Storage::disk('local')->put($this->tempPdfPath((int) $user->tenant_id, $token),
            file_get_contents($request->file('pdf')->getRealPath()));
        $this->pruneTempPdfs((int) $user->tenant_id);

        $url = URL::temporarySignedRoute('order-confirmations.signed-pdf', now()->addMinutes(10), [
            't'  => (int) $user->tenant_id,
            'u'  => (int) $user->id,
            'id' => $id,
            'dl' => $request->boolean('download') ? 1 : 0,
            'f'  => $token,
        ]);

        return response()->json(['url' => $url, 'expires_in' => 600]);
    }

    private function tempPdfPath(int $tenantId, string $token): string
    {
        return 'oc_pdf_tmp/' . $tenantId . '/' . $token . '.pdf';
    }

    private function pruneTempPdfs(int $tenantId): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files('oc_pdf_tmp/' . $tenantId) as $f) {
            if ($disk->lastModified($f) < now()->subMinutes(15)->getTimestamp()) $disk->delete($f);
        }
    }

    /**
     * GET /api/oc-pdf (signed, no Sanctum) — serves the PDF for a link
     * produced by pdfLink(). Fails closed: tenant must be active, the user
     * must still belong to it, be active and hold the view permission, and
     * the order must still be inside the user's Bereich scope.
     */
    public function signedPdf(Request $request)
    {
        $tenant = Tenant::where('id', (int) $request->query('t'))->where('aktiv', true)->first();
        $user = $tenant
            ? CentralUser::where('id', (int) $request->query('u'))->where('tenant_id', $tenant->id)->first()
            : null;
        if (!$tenant || !$user
            || !in_array('auftraege.bestaetigung.view', $user->getEffectivePermissions(), true)) {
            abort(403, 'Link ungültig');
        }

        TenantService::switchToTenant($tenant);

        // Archived (sent) version — see pdfLink(?archive=1).
        $arc = (int) $request->query('arc', 0);
        if ($arc > 0) {
            $id = (string) $request->query('id');
            if (!$this->hasVersions() || !BereichScope::allowsEingang($user, $id)) abort(404);
            return $this->streamArchive((int) $tenant->id, $id, $arc, $request->boolean('dl'));
        }

        // Client-rendered binary (editor layout applied) — see pdfLinkUpload.
        $token = (string) $request->query('f', '');
        if ($token !== '') {
            $id = (string) $request->query('id');
            if (!preg_match('/^[a-f0-9]{32}$/', $token) || !BereichScope::allowsEingang($user, $id)) abort(404);
            $path = $this->tempPdfPath((int) $tenant->id, $token);
            if (!Storage::disk('local')->exists($path)) abort(410, 'Link abgelaufen');
            $name = 'Auftragsbestaetigung_' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $id) . '.pdf';
            return response(Storage::disk('local')->get($path), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => ($request->boolean('dl') ? 'attachment' : 'inline') . '; filename="' . $name . '"',
                'Cache-Control'       => 'no-store',
            ]);
        }

        return $this->renderPdf($request, (string) $request->query('id'), $user, $request->boolean('dl'));
    }

    /**
     * Einleitung / Schlusstext of the AB template (Dokumentvorlagen →
     * Textbausteine, storage/app/pdf_layouts/{tenant}/ab.json). Same rules as
     * order_form.js: no saved slot → the standard text, switched ON; a saved
     * slot is used as-is (off or empty → nothing printed). Placeholders
     * {{…}} are filled from the document, an empty "()" is dropped.
     */
    protected function abTextbausteine(int $tenantId, array $data): array
    {
        $defaults = [
            'intro'   => 'Gerne bestätigen wir hiermit Ihren Auftrag für {{zeitraum}} ({{leistungszeit}}) zu folgenden Positionen:',
            'closing' => "Wir bedanken uns herzlich für Ihren Auftrag!\n\nMit freundlichen Grüßen",
        ];
        $layout = null;
        $path = 'pdf_layouts/' . $tenantId . '/ab.json';
        try {
            if (Storage::disk('local')->exists($path)) $layout = json_decode((string) Storage::disk('local')->get($path), true);
        } catch (\Throwable $e) { $layout = null; }
        $saved = is_array($layout['bausteine'] ?? null) ? $layout['bausteine'] : [];
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $ctx = [
            'firma'         => (string) ($data['company']['name'] ?? ''),
            'kunde'         => (string) ($data['kunde']['name'] ?? ''),
            'objekt'        => (string) ($data['lieferadresse'] ?? ''),
            'zeitraum'      => (string) ($meta['zeitraum'] ?? ''),
            'leistungszeit' => (string) ($meta['leistungszeit'] ?? ''),
            'dokument_nr'   => (string) ($data['dokument_nr'] ?? ($meta['auftrags_nr'] ?? '')),
            'mitarbeiter'   => '',
            'datum'         => (string) ($meta['datum'] ?? now()->format('d.m.Y')),
        ];
        $out = [];
        foreach ($defaults as $slot => $default) {
            $b = is_array($saved[$slot] ?? null) ? $saved[$slot] : null;
            $on = $b === null ? true : !empty($b['on']);
            $text = ($b !== null && is_string($b['text'] ?? null)) ? $b['text'] : $default;
            $text = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => $ctx[$m[1]] ?? $m[0], $text);
            $text = trim(preg_replace('/\s*\(\s*\)/', '', $text));
            $out[$slot] = ($on && $text !== '') ? $text : null;
        }
        return $out;
    }

    protected function renderPdf(Request $request, string $id, $user, bool $download)
    {
        $data = $this->buildConfirmationData($id, $user);
        if (!$data) {
            return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        }

        $settings = $this->resolvePdfSettings();
        $override = $request->input('settings', []);
        if (is_string($override)) $override = json_decode($override, true) ?: [];
        $data['settings'] = is_array($override) ? array_merge($settings, $override) : $settings;
        if (!empty($data['settings']['hide_logo'])) $data['logoDataUri'] = null;
        $data['abTexts'] = $this->abTextbausteine((int) $user->tenant_id, $data);

        $pdf = Pdf::loadView('pdf.auftragsbestaetigung', $data);
        $pdf->setPaper('A4', 'portrait');

        $filename = 'Auftragsbestaetigung_' . $data['dokument_nr'] . '.pdf';
        return $download ? $pdf->download($filename) : $pdf->stream($filename);
    }

    /**
     * POST /api/order-confirmations/{id}/sent
     * Marks the confirmation as sent to the customer (ab_sent_at/by).
     */
    public function markSent(Request $request, string $id)
    {
        if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'ab_sent_at')) {
            return response()->json(['error' => 'Versand-Status ist auf diesem Tenant noch nicht aktiv. Bitte tenant:migrate ausführen.'], 503);
        }
        $existing = DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->first();
        if (!$existing || !BereichScope::allowsEingang($request->user(), $id)) {
            return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        }

        $user = $request->user();
        if (!$this->hasVersions()) {
            // Tenant not migrated yet → previous behaviour (flag only).
            DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->update([
                'ab_sent_at' => now(),
                'ab_sent_by' => optional($user)->id,
            ]);
            \App\Helpers\Realtime::changed('eingang');
            return response()->json(['success' => true, 'ab_sent_at' => now()->toDateTimeString()]);
        }

        // Freeze: the client renders the PDF with the editor (company
        // template) from the live data of ?live=1 and sends its content
        // hash — if the order changed in between, the render is stale.
        $request->validate([
            'pdf'          => 'required|file|mimetypes:application/pdf|max:15360',
            'content_hash' => 'required|string|size:64',
        ]);
        $data = $this->buildConfirmationData($id, $user);
        if (!$data) return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        if (!hash_equals($data['content_hash'], (string) $request->input('content_hash'))) {
            return response()->json(['error' => 'Die Auftragsdaten wurden inzwischen geändert — bitte erneut versenden.'], 409);
        }

        $tenantId = (int) $user->tenant_id;
        $result = DB::connection('tenant')->transaction(function () use ($id, $data, $request, $user, $tenantId) {
            $version = (int) DB::connection('tenant')->table('auftragsbestaetigung_versionen')
                ->where('eingang_id', $id)->lockForUpdate()->max('version') + 1;
            $payload = $this->documentPayload($data);
            $payload['meta']['version'] = $version;
            $payload['meta']['datum'] = now()->format('d.m.Y');   // document date = date of issue
            $path = $this->archivePath($tenantId, $id, $version);
            Storage::disk('local')->put($path, file_get_contents($request->file('pdf')->getRealPath()));
            DB::connection('tenant')->table('auftragsbestaetigung_versionen')->insert([
                'eingang_id'   => $id,
                'version'      => $version,
                'pdf_path'     => $path,
                'data'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'content_hash' => $data['content_hash'],
                'sent_at'      => now(),
                'sent_by'      => optional($user)->id,
                'created_at'   => now(),
            ]);
            DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->update([
                'ab_sent_at' => now(),
                'ab_sent_by' => optional($user)->id,
                'ab_version' => $version,
            ]);
            return $version;
        });
        \App\Helpers\Realtime::changed('eingang');

        return response()->json(['success' => true, 'version' => $result, 'ab_sent_at' => now()->toDateTimeString()]);
    }

    /**
     * DELETE /api/order-confirmations/{id}/sent
     * Reverts the sent flag (e.g. marked by mistake).
     */
    public function unmarkSent(Request $request, string $id)
    {
        if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'ab_sent_at')) {
            return response()->json(['error' => 'Versand-Status ist auf diesem Tenant noch nicht aktiv. Bitte tenant:migrate ausführen.'], 503);
        }
        $existing = DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->first();
        if (!$existing || !BereichScope::allowsEingang($request->user(), $id)) {
            return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        }

        // "Zurücknehmen" corrects a send marked by mistake: the latest
        // archived version is discarded; an older one (if any) becomes the
        // sent version again.
        $update = ['ab_sent_at' => null, 'ab_sent_by' => null];
        if ($this->hasVersions()) {
            $latest = DB::connection('tenant')->table('auftragsbestaetigung_versionen')
                ->where('eingang_id', $id)->orderByDesc('version')->first();
            if ($latest && (int) $latest->version === (int) ($existing->ab_version ?? 0)) {
                Storage::disk('local')->delete($latest->pdf_path);
                DB::connection('tenant')->table('auftragsbestaetigung_versionen')->where('id', $latest->id)->delete();
            }
            $prev = DB::connection('tenant')->table('auftragsbestaetigung_versionen')
                ->where('eingang_id', $id)->orderByDesc('version')->first();
            $update = $prev
                ? ['ab_sent_at' => $prev->sent_at, 'ab_sent_by' => $prev->sent_by, 'ab_version' => $prev->version]
                : $update + ['ab_version' => null];
        }
        DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->update($update);
        \App\Helpers\Realtime::changed('eingang');

        return response()->json(['success' => true, 'still_sent_version' => $update['ab_version'] ?? null]);
    }

    // ===========================================================
    // Data assembly
    // ===========================================================

    /**
     * Everything the blade + the JSON preview need for one Bestelleingang.
     * Returns null when the order is missing or out of the caller's scope.
     */
    protected function buildConfirmationData(string $id, $user): ?array
    {
        $be = DB::connection('tenant')
            ->table('bestelleingang as be')
            ->leftJoin('kunden as k', 'be.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'be.obj_id', '=', 'o.obj_id')
            ->select(
                'be.*',
                'k.kundenname', 'k.strasse as kunde_strasse', 'k.zusatz as kunde_zusatz',
                'k.plz as kunde_plz', 'k.ort as kunde_ort', 'k.land as kunde_land',
                'k.kunden_nr_kunde',
                'o.objektname', 'o.adresse as objekt_adresse',
                'o.plz as objekt_plz', 'o.stadt as objekt_stadt'
            )
            ->where('be.eingang_id', $id)
            ->first();

        if (!$be || !BereichScope::allowsEingang($user, $id)) return null;

        // Kundenbetreuer (kunden.betreuer_id) — default "Bearbeiter" of the
        // customer's documents (see resolveBearbeiter).
        if (Schema::connection('tenant')->hasColumn('kunden', 'betreuer_id')) {
            $be->kunde_betreuer_id = DB::connection('tenant')->table('kunden')
                ->where('kunde_id', $be->kunden_id)->value('betreuer_id');
        }

        if (Schema::connection('tenant')->hasColumn('objekte', 'bestellbeschreibung')) {
            $be->bestellbeschreibung = DB::connection('tenant')->table('objekte')
                ->where('obj_id', $be->obj_id)->value('bestellbeschreibung');
        }

        $bestellung = DB::connection('tenant')->table('bestellungen')
            ->where('eingang_id', $id)->orderBy('bestell_id')->first();

        $artikelIds = $this->artikelIdsOf($be);
        $artikelMap = !empty($artikelIds)
            ? DB::connection('tenant')->table('artikel')
                ->whereIn('artikel_id', $artikelIds)->get()->keyBy('artikel_id')
            : collect();

        $tage = DB::connection('tenant')->table('bestelleingang_tage')
            ->where('eingang_id', $id)->orderBy('datum')->get();

        $calc = $this->computePositions($artikelIds, $this->preiseOf($be), $tage, $artikelMap, [
            'modell' => $be->modell, 'kundenpreis' => $be->kundenpreis,
        ]);

        // Document meta (German business letter conventions)
        $datumMin = $tage->min('datum');
        $datumMax = $tage->max('datum');
        $dokumentNr = $bestellung->bestell_id ?? $be->eingang_id;
        $bearbeiter = $this->resolveBearbeiter($be, $user);
        $meta = [
            'auftrags_nr' => $dokumentNr,
            'kunden_nr'   => trim((string) ($be->kunden_nr_kunde ?? '')) !== ''
                ? $be->kunden_nr_kunde : $be->kunden_id,
            'bestell_nr'  => $bestellung->kunden_obj_nr ?? null,
            'datum'       => now()->format('d.m.Y'),
            // Responsible person of THIS order (not whoever opens the PDF).
            'bearbeiter'  => $bearbeiter['name'],
            'telefon'     => null, // filled below from tenant profile
            'email'       => null,
            'zeitraum'    => (self::MONTHS_DE[(int) $be->monat] ?? $be->monat) . ' ' . $be->jahr,
            'leistungszeit' => ($be->zeit_von || $be->zeit_bis)
                ? substr((string) $be->zeit_von, 0, 5) . ' – ' . substr((string) $be->zeit_bis, 0, 5) . ' Uhr'
                : null,
            'datum_von'   => $datumMin ? \Carbon\Carbon::parse($datumMin)->format('d.m.Y') : null,
            'datum_bis'   => $datumMax ? \Carbon\Carbon::parse($datumMax)->format('d.m.Y') : null,
        ];

        $kunde = [
            'name'    => $be->kundenname ?? $be->kunden_id,
            'zusatz'  => $be->kunde_zusatz,
            'strasse' => $be->kunde_strasse,
            'plz_ort' => trim(($be->kunde_plz ?? '') . ' ' . ($be->kunde_ort ?? '')),
            'land'    => in_array($be->kunde_land ?? 'D', ['D', 'DE']) ? null : ($be->kunde_land ?? null),
        ];

        $lieferadresse = null;
        $objektParts = array_filter([
            $be->objektname,
            $be->objekt_adresse,
            trim(($be->objekt_plz ?? '') . ' ' . ($be->objekt_stadt ?? '')),
        ]);
        if (!empty($objektParts)) $lieferadresse = implode(', ', $objektParts);

        $bemerkungenAuto = array_values(array_filter([
            trim((string) ($be->bemerkung ?? '')) !== '' ? trim((string) $be->bemerkung) : null,
            trim((string) ($be->bestellbeschreibung ?? '')) !== '' ? trim((string) $be->bestellbeschreibung) : null,
        ]));
        // Own text written in the AB preview replaces the automatic notes.
        $bemerkungen = (isset($be->ab_bemerkung) && trim((string) $be->ab_bemerkung) !== '')
            ? [trim((string) $be->ab_bemerkung)] : $bemerkungenAuto;

        $tenant  = TenantService::current();
        $company = [
            'name'              => $tenant->name ?? 'Firma',
            'address'           => $tenant->company_address ?? '',
            'zip'               => $tenant->company_zip ?? '',
            'city'              => $tenant->company_city ?? '',
            'phone'             => $tenant->company_phone ?? '',
            'email'             => $tenant->company_email ?? '',
            'website'           => $tenant->website ?? '',
            'logo_path'         => $tenant->logo_path ?? null,
            'rechtsform'         => $tenant->rechtsform ?? '',
            'geschaeftsfuehrer'  => $tenant->geschaeftsfuehrer ?? '',
            'handelsregister_nr' => $tenant->handelsregister_nr ?? '',
            'registergericht'    => $tenant->registergericht ?? '',
            'ust_id'             => $tenant->ust_id ?? '',
            'steuernummer'       => $tenant->steuernummer ?? '',
            'bank_name'          => $tenant->bank_name ?? '',
            'iban'               => $tenant->iban ?? '',
            'bic'                => $tenant->bic ?? '',
        ];
        $meta['telefon'] = $company['phone'] ?: null;
        $meta['email']   = $company['email'] ?: null;

        // Who marked the confirmation sent (for the preview modal).
        $abSentByName = null;
        if (!empty($be->ab_sent_by) && $user) {
            $abSentByName = DB::connection('mysql')->table('central_users')
                ->where('tenant_id', (int) $user->tenant_id)
                ->where('id', $be->ab_sent_by)
                ->whereRaw("name NOT LIKE 'deleted\\_%'")
                ->value('full_name');
        }

        return [
            'eingang'         => $be,
            'bestellung'      => $bestellung,
            'dokument_nr'     => $dokumentNr,
            'bearbeiter'      => $bearbeiter,
            'content_hash'    => $this->contentHash($calc['positions'], $calc['totals'], $meta['zeitraum'], $meta['leistungszeit'], $bearbeiter['ref'], $be->ab_bemerkung ?? null),
            'bemerkungen_auto' => $bemerkungenAuto,
            'meta'            => $meta,
            'kunde'           => $kunde,
            'lieferadresse'   => $lieferadresse,
            'positions'       => $calc['positions'],
            'totals'          => $calc['totals'],
            'bemerkungen'     => $bemerkungen,
            'ab_sent_by_name' => $abSentByName,
            'company'         => $company,
            'logoDataUri'     => $this->resolveLogoDataUri((int) ($tenant->id ?? 0), $company['logo_path']),
            'settings'        => [],
        ];
    }

    /**
     * Compute priced positions for one order. Shared by index() (totals
     * preview) and buildConfirmationData() so the list sum always equals
     * the document total.
     */
    protected function computePositions(array $artikelIds, array $preise, $tage, $artikelMap, array $fallback): array
    {
        $primary = $artikelIds[0] ?? null;

        // Group days by their resolved artikel (same rule as
        // IncomingOrderController::tagArtikelId — a day may only point at
        // a SELECTED service, everything else lands on the primary).
        $daysByArtikel = [];
        foreach ($tage as $t) {
            $ta = trim((string) ($t->artikel_id ?? ''));
            $resolved = ($ta !== '' && in_array($ta, $artikelIds, true)) ? $ta : $primary;
            $key = $resolved ?? '_fallback';
            $daysByArtikel[$key][] = $t;
        }

        $positions = [];
        foreach ($artikelIds as $aid) {
            $master = $artikelMap->get($aid);
            $p      = is_array($preise[$aid] ?? null) ? $preise[$aid] : [];
            $modell = trim((string) ($p['modell'] ?? '')) !== ''
                ? trim((string) $p['modell']) : ($master->modell ?? $fallback['modell'] ?? 'Pro Einsatz');
            $preis  = isset($p['kundenpreis']) && $p['kundenpreis'] !== '' && $p['kundenpreis'] !== null
                ? (float) $p['kundenpreis']
                : (float) ($master->preis_kunde ?? $fallback['kundenpreis'] ?? 0);

            $days = $daysByArtikel[$aid] ?? [];
            $tageCount = count($days);
            $stunden = 0.0;
            foreach ($days as $d) {
                $stunden += (float) ($d->soll_std ?? 0) * (float) ($d->menge ?? 1);
            }

            switch ($modell) {
                case 'Pro Stunde':
                    $qty = $stunden;  $einheit = 'Std.';  break;
                case 'Monatspauschale':
                    $qty = 1.0;       $einheit = 'Pausch.'; break;
                case 'Pro Einsatz':
                    $qty = (float) $tageCount; $einheit = 'Eins.'; break;
                default:
                    $qty = (float) max($tageCount, 1);
                    $einheit = trim((string) ($master->einheit ?? '')) !== ''
                        ? $master->einheit : 'Eins.';
            }

            $dateMin = $days ? min(array_map(fn ($d) => substr((string) $d->datum, 0, 10), $days)) : null;
            $dateMax = $days ? max(array_map(fn ($d) => substr((string) $d->datum, 0, 10), $days)) : null;

            $positions[] = [
                'artikel_id' => $aid,
                'name'       => $master->artikel_name ?? $aid,
                'modell'     => $modell,
                'bemerkung'  => trim((string) ($p['bemerkung'] ?? '')) ?: null,
                'tage_count' => $tageCount,
                'stunden'    => round($stunden, 2),
                'datum_von'  => $dateMin,
                'datum_bis'  => $dateMax,
                'menge'      => round($qty, 2),
                'einheit'    => $einheit,
                'preis'      => $preis,
                'gesamt'     => round($qty * $preis, 2),
            ];
        }

        // Legacy orders without any artikel: a single blanket position so
        // the document still bills the header price.
        if (empty($positions)) {
            $tageCount = $tage->count();
            $modell = $fallback['modell'] ?? 'Pro Einsatz';
            $qty = $modell === 'Monatspauschale' ? 1.0 : (float) max($tageCount, 1);
            $positions[] = [
                'artikel_id' => null,
                'name'       => 'Dienstleistungen',
                'modell'     => $modell,
                'bemerkung'  => null,
                'tage_count' => $tageCount,
                'stunden'    => 0.0,
                'datum_von'  => null,
                'datum_bis'  => null,
                'menge'      => $qty,
                'einheit'    => $modell === 'Monatspauschale' ? 'Pausch.' : 'Eins.',
                'preis'      => (float) ($fallback['kundenpreis'] ?? 0),
                'gesamt'     => round($qty * (float) ($fallback['kundenpreis'] ?? 0), 2),
            ];
        }

        $netto  = round(array_sum(array_column($positions, 'gesamt')), 2);
        $ust    = round($netto * self::UST_RATE / 100, 2);
        return [
            'positions' => $positions,
            'totals'    => [
                'netto'    => $netto,
                'ust_rate' => self::UST_RATE,
                'ust'      => $ust,
                'brutto'   => round($netto + $ust, 2),
            ],
        ];
    }

    // ===========================================================
    // Helpers
    // ===========================================================

    /**
     * "Bearbeiter" of an order, in this order:
     *   1. the person chosen on the order itself (ab_bearbeiter_id),
     *   2. the customer's Kundenbetreuer (kunden.betreuer_id),
     *   3. the creator of the order (created_by),
     *   4. legacy rows without any of these — the current user.
     * `ref` is the stored id (0 when none) and feeds the content hash, so the
     * hash never depends on who happens to open the document. `source` tells
     * the UI where the name comes from.
     */
    protected function bearbeiterRef($be): int
    {
        return (int) (($be->ab_bearbeiter_id ?? null) ?: ($be->kunde_betreuer_id ?? null) ?: ($be->created_by ?? null) ?: 0);
    }

    protected function resolveBearbeiter($be, $user): array
    {
        $ref = $this->bearbeiterRef($be);
        $source = !empty($be->ab_bearbeiter_id) ? 'auftrag'
            : (!empty($be->kunde_betreuer_id) ? 'kundenbetreuer' : (!empty($be->created_by) ? 'ersteller' : 'aktuell'));
        $name = null;
        if ($ref && $user) {
            $u = DB::connection('mysql')->table('central_users')
                ->where('tenant_id', (int) $user->tenant_id)->where('id', $ref)
                ->whereRaw("name NOT LIKE 'deleted\\_%'")
                ->first(['full_name', 'name', 'email']);
            if ($u) $name = trim((string) $u->full_name) !== '' ? $u->full_name : ($u->name ?: $u->email);
        }
        if ($name === null && $user) {
            $name = trim((string) ($user->full_name ?? '')) !== '' ? $user->full_name : ($user->name ?? $user->email ?? null);
        }
        return ['id' => $ref ?: null, 'ref' => $ref, 'name' => $name, 'chosen' => !empty($be->ab_bearbeiter_id), 'source' => $source];
    }

    /**
     * Fingerprint of the commercially relevant content (positions, totals,
     * period, service time, Bearbeiter). Computed identically by index()
     * and buildConfirmationData() — a sent version whose live hash differs
     * was changed after sending → "neue Version senden".
     */
    protected function contentHash(array $positions, array $totals, $zeitraum, $leistungszeit, int $bearbeiterRef, ?string $abBemerkung = null): string
    {
        $pos = array_map(fn ($p) => [
            $p['artikel_id'] ?? null, $p['name'] ?? null, $p['modell'] ?? null, $p['bemerkung'] ?? null,
            (int) ($p['tage_count'] ?? 0), round((float) ($p['stunden'] ?? 0), 2), $p['datum_von'] ?? null, $p['datum_bis'] ?? null,
            round((float) ($p['menge'] ?? 0), 2), $p['einheit'] ?? null, round((float) ($p['preis'] ?? 0), 2), round((float) ($p['gesamt'] ?? 0), 2),
        ], $positions);
        $tot = [round((float) ($totals['netto'] ?? 0), 2), round((float) ($totals['ust'] ?? 0), 2), round((float) ($totals['brutto'] ?? 0), 2)];
        $parts = [$pos, $tot, (string) $zeitraum, (string) $leistungszeit, $bearbeiterRef];
        // Own Bemerkungen text only when set — hashes of untouched orders
        // (and of every version sent before this field existed) stay equal.
        if ($abBemerkung !== null && $abBemerkung !== '') $parts[] = $abBemerkung;
        return hash('sha256', json_encode($parts));
    }

    protected function hasVersions(): bool
    {
        return Schema::connection('tenant')->hasTable('auftragsbestaetigung_versionen')
            && Schema::connection('tenant')->hasColumn('bestelleingang', 'ab_version');
    }

    /** All archived versions of an order, newest first (without the data blob). */
    protected function versionsOf(string $id, $user): array
    {
        if (!$this->hasVersions()) return [];
        $rows = DB::connection('tenant')->table('auftragsbestaetigung_versionen')
            ->where('eingang_id', $id)->orderByDesc('version')
            ->get(['version', 'sent_at', 'sent_by', 'content_hash']);
        $ids = $rows->pluck('sent_by')->filter()->unique()->values()->all();
        $names = ($ids && $user) ? DB::connection('mysql')->table('central_users')
            ->where('tenant_id', (int) $user->tenant_id)->whereIn('id', $ids)->pluck('full_name', 'id')->all() : [];
        return $rows->map(fn ($r) => [
            'version'      => (int) $r->version,
            'sent_at'      => $r->sent_at,
            'sent_by_name' => $r->sent_by ? ($names[$r->sent_by] ?? null) : null,
            'content_hash' => $r->content_hash,
        ])->all();
    }

    private function archivePath(int $tenantId, string $id, int $version): string
    {
        return 'ab_archive/' . $tenantId . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $id) . '/v' . $version . '.pdf';
    }

    /** Selected artikel ids of an order row ([0] = primary). */
    protected function artikelIdsOf($row): array
    {
        $raw = $row->artikel_ids ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($v) => trim((string) $v), is_array($raw) ? $raw : [])
        )));
        if (empty($ids) && !empty($row->artikel_id)) $ids = [trim((string) $row->artikel_id)];
        return $ids;
    }

    /** Per-artikel pricing overrides stored as JSON on the order. */
    protected function preiseOf($row): array
    {
        $decoded = json_decode((string) ($row->artikel_preise ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Default pdf_template settings (Dokument-Design) for this tenant. */
    protected function resolvePdfSettings(): array
    {
        if (!Schema::connection('tenant')->hasTable('pdf_templates')) return [];
        $t = DB::connection('tenant')->table('pdf_templates')
            ->where('is_default', 1)->first()
            ?? DB::connection('tenant')->table('pdf_templates')
                ->where('is_system', 1)->orderBy('id')->first();
        if (!$t) return [];
        $settings = json_decode((string) $t->settings, true);
        return is_array($settings) ? $settings : [];
    }

    /**
     * Tenant logo as data: URI — same in-process cache strategy as
     * PdfController::resolveLogoDataUri (keyed by tenant+path+mtime).
     */
    protected function resolveLogoDataUri(int $tenantId, ?string $logoPath): ?string
    {
        if (!$logoPath) return null;
        $fullPath = storage_path('app/public/' . $logoPath);
        if (!is_file($fullPath)) return null;

        $key = sprintf('pdf:logo:%d:%s:%d', $tenantId, md5($logoPath), filemtime($fullPath));
        return Cache::driver('array')->remember($key, 600, function () use ($fullPath) {
            $type = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            if ($type === 'jpg') $type = 'jpeg';
            $data = @file_get_contents($fullPath);
            if ($data === false) return null;
            return 'data:image/' . $type . ';base64,' . base64_encode($data);
        });
    }
}
