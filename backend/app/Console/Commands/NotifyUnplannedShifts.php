<?php

namespace App\Console\Commands;

use App\Helpers\NotificationDispatcher;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Daily reminder to the Dispo-responsible users (owner + auftraege.dispo.*)
 * that there are still UPCOMING shifts (einsaetze) which have not been
 * planned yet.
 *
 * "Unplanned" reuses the canonical definition used everywhere else in the
 * system (dashboard widgets, AI tools): an einsatz is OPEN when
 *     hms_bestaetigt = 'ausstehend' AND COALESCE(pruefung,'') <> 'geplant'
 * Scoped to datum >= today so shifts in the past are ignored.
 *
 * Sends ONE in-app bell notification (type 'dispo.unplanned', link '#tasks')
 * + one styled HTML email per recipient summarising the open shifts. Runs
 * daily (see App\Console\Kernel) and repeats every morning until everything
 * is planned. No DB schema change required.
 *
 * Usage:
 *   php artisan dispo:notify-unplanned            # all active tenants
 *   php artisan dispo:notify-unplanned --tenant=acme
 *   php artisan dispo:notify-unplanned --dry-run  # report only, no send
 */
class NotifyUnplannedShifts extends Command
{
    protected $signature = 'dispo:notify-unplanned
                            {--tenant= : Limit to a single tenant slug}
                            {--dry-run : Report only; do not send notifications/emails}';

    protected $description = 'Notify Dispo managers about upcoming shifts that are not yet planned';

    /** Max number of shifts listed in the email preview table. */
    private const PREVIEW_LIMIT = 25;

    public function handle(): int
    {
        $slug   = $this->option('tenant');
        $dryRun = (bool) $this->option('dry-run');

        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::where('aktiv', true)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants.');
            return 0;
        }

        $today = Carbon::today()->toDateString();
        $this->info(sprintf(
            '%s unplanned upcoming shifts (datum >= %s) across %d tenant(s)…',
            $dryRun ? '[DRY-RUN] Checking' : 'Checking',
            $today,
            $tenants->count(),
        ));

        $grandTotal      = 0;
        $tenantsNotified = 0;

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);

            if (!Schema::connection('tenant')->hasTable('einsaetze')
                || !Schema::connection('tenant')->hasTable('notifications')) {
                continue;
            }

            $total = (int) DB::connection('tenant')->table('einsaetze')
                ->whereDate('datum', '>=', $today)
                ->where('hms_bestaetigt', 'ausstehend')
                ->whereRaw("COALESCE(pruefung,'') <> 'geplant'")
                ->count();

            if ($total === 0) {
                $this->line(sprintf('  %-30s open=0', substr($tenant->slug, 0, 30)));
                continue;
            }

            // Nearest-date-first preview rows for the email.
            $rows = DB::connection('tenant')->table('einsaetze as e')
                ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
                ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
                ->whereDate('e.datum', '>=', $today)
                ->where('e.hms_bestaetigt', 'ausstehend')
                ->whereRaw("COALESCE(e.pruefung,'') <> 'geplant'")
                ->orderBy('e.datum')
                ->limit(self::PREVIEW_LIMIT)
                ->get(['e.einsatz_id', 'e.datum', 'e.ist_start', 'e.ist_ende', 'k.kundenname', 'o.objektname']);

            // Defensive: the count and the preview are separate queries; if a
            // concurrent edit removed the rows in between, skip this tenant.
            if ($rows->isEmpty()) {
                continue;
            }

            $earliest = Carbon::parse($rows->first()->datum)->format('d.m.Y');

            $shifts = $rows->map(function ($r) {
                $start = substr((string) $r->ist_start, 0, 5);
                $ende  = substr((string) $r->ist_ende, 0, 5);
                if ($start !== '' && $ende !== '') {
                    $zeit = $start . ' – ' . $ende;
                } elseif ($start !== '') {
                    $zeit = 'ab ' . $start;
                } elseif ($ende !== '') {
                    $zeit = 'bis ' . $ende;
                } else {
                    $zeit = '–';
                }
                return [
                    'datum'  => Carbon::parse($r->datum)->format('d.m.Y'),
                    'zeit'   => $zeit,
                    'kunde'  => $r->kundenname ?: '–',
                    'objekt' => $r->objektname ?: '–',
                ];
            })->all();

            $this->line(sprintf(
                '  %-30s open=%-4d earliest=%s',
                substr($tenant->slug, 0, 30),
                $total,
                $earliest,
            ));
            $grandTotal += $total;

            if ($dryRun) {
                continue;
            }

            $title = $total === 1
                ? '1 Einsatz noch nicht geplant'
                : "{$total} Einsätze noch nicht geplant";

            $body = ($total === 1
                    ? 'Es gibt 1 offenen Einsatz ab heute, der noch eingeplant werden muss.'
                    : "Es gibt {$total} offene Einsätze ab heute, die noch eingeplant werden müssen.")
                . "\nFrühester Termin: {$earliest}."
                . "\nÖffnen Sie Einsätze & Dispo, um die Planung abzuschließen.";

            $companyName = $tenant->name ?: config('app.name', 'Clood-x');
            $portalUrl   = rtrim((string) config('app.url', ''), '/');
            $portalUrl   = $portalUrl ? $portalUrl . '/index.php#tasks' : null;

            $htmlBody = null;
            try {
                $htmlBody = view('emails.dispo-unplanned-shifts', [
                    'company'      => $companyName,
                    'total'        => $total,
                    'earliest'     => $earliest,
                    'shifts'       => $shifts,
                    'previewLimit' => self::PREVIEW_LIMIT,
                    'portalUrl'    => $portalUrl,
                ])->render();
            } catch (\Throwable $e) {
                Log::warning('dispo-unplanned-shifts template render failed: ' . $e->getMessage());
            }

            $sent = NotificationDispatcher::notifyDispoManagers(
                (int) $tenant->id,
                'dispo.unplanned',
                $title,
                $body,
                '#tasks',
                [
                    'open_count' => $total,
                    'earliest'   => $earliest,
                    'date'       => $today,
                ],
                $htmlBody
            );

            if ($sent > 0) {
                $tenantsNotified++;
            }
        }

        TenantService::reset();

        $this->newLine();
        $this->info(sprintf(
            '%s %d open shift(s) across %d tenant(s); notified %d tenant(s).',
            $dryRun ? '[DRY-RUN] Found' : 'Reported',
            $grandTotal,
            $tenants->count(),
            $tenantsNotified,
        ));

        return 0;
    }
}
