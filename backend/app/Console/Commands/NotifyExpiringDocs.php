<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\WorkerDocRenewalController;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily sweep: for every worker document that is expired or expires within
 * --days (default 14), automatically open a renewal request (if none is
 * already open for it) and notify the worker (in-app + email) to upload a
 * replacement PDF through the worker app.
 *
 * The uploaded replacement does NOT go live automatically — HR reviews and
 * approves it first (see WorkerDocRenewalController).
 *
 * Usage:
 *   php artisan docs:notify-expiring              # all active tenants
 *   php artisan docs:notify-expiring --tenant=ddr
 *   php artisan docs:notify-expiring --days=30
 *   php artisan docs:notify-expiring --dry-run
 */
class NotifyExpiringDocs extends Command
{
    protected $signature = 'docs:notify-expiring
                            {--tenant= : Limit to a single tenant slug}
                            {--days=14 : Lead time in days before expiry}
                            {--dry-run : Report only; do not create requests or send mail}';

    protected $description = 'Open renewal requests + notify workers about expired/expiring documents';

    public function handle(): int
    {
        $slug   = $this->option('tenant');
        $days   = max(0, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::where('aktiv', true)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants.');
            return 0;
        }

        $cutoff = Carbon::today()->addDays($days)->toDateString();
        $created = 0;

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);

            if (!Schema::connection('tenant')->hasTable('mitarbeiter_dokumente')
                || !Schema::connection('tenant')->hasTable('mitarbeiter_dokument_anfragen')) {
                continue;
            }

            // Expired or expiring soon docs of ACTIVE workers without an
            // open renewal request for the same document.
            $docs = DB::connection('tenant')->table('mitarbeiter_dokumente as d')
                ->join('mitarbeiter as m', 'd.dienst_nr', '=', 'm.dienst_nr')
                ->leftJoin('mitarbeiter_dokument_anfragen as a', function ($j) {
                    $j->on('a.dokument_id', '=', 'd.id')
                      ->whereIn('a.status', ['angefordert', 'hochgeladen', 'abgelehnt']);
                })
                ->whereNull('a.id')
                ->where('m.aktiv', 1)
                ->whereNotNull('d.ablaufdatum')
                ->whereDate('d.ablaufdatum', '<=', $cutoff)
                ->get(['d.id', 'd.dienst_nr', 'd.titel', 'd.ablaufdatum',
                       'm.id as m_id', 'm.vorname', 'm.name', 'm.email']);

            if ($docs->isEmpty()) {
                $this->line(sprintf('  %-30s due=0', substr($tenant->slug, 0, 30)));
                continue;
            }

            $this->line(sprintf('  %-30s due=%d', substr($tenant->slug, 0, 30), $docs->count()));

            foreach ($docs as $doc) {
                if ($dryRun) {
                    $this->line("    [DRY] {$doc->dienst_nr} — {$doc->titel} (Ablauf {$doc->ablaufdatum})");
                    continue;
                }

                DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')->insert([
                    'dienst_nr'       => $doc->dienst_nr,
                    'dokument_id'     => $doc->id,
                    'titel'           => $doc->titel,
                    'nachricht'       => 'Ablaufdatum: ' . Carbon::parse($doc->ablaufdatum)->format('d.m.Y'),
                    'status'          => 'angefordert',
                    'angefordert_von' => 'System (automatische Ablaufprüfung)',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                $anfrageId = DB::connection('tenant')->getPdo()->lastInsertId();

                // Log the initial notification.
                try {
                    DB::connection('tenant')->table('mitarbeiter_dokument_anfragen_log')->insert([
                        'anfrage_id' => $anfrageId,
                        'aktion'     => 'requested',
                        'sender'     => 'System (automatische Ablaufprüfung)',
                    ]);
                } catch (\Throwable $e) { /* non-fatal */ }

                $worker = (object) [
                    'id'        => $doc->m_id,
                    'dienst_nr' => $doc->dienst_nr,
                    'vorname'   => $doc->vorname,
                    'name'      => $doc->name,
                    'email'     => $doc->email,
                ];
                WorkerDocRenewalController::notifyWorkerRequested(
                    $worker,
                    $doc->titel,
                    'Ablaufdatum: ' . Carbon::parse($doc->ablaufdatum)->format('d.m.Y')
                );
                $created++;
            }
        }

        TenantService::reset();

        $this->info(sprintf(
            '%s %d renewal request(s) across %d tenant(s).',
            $dryRun ? '[DRY-RUN] Would create' : 'Created',
            $created,
            $tenants->count(),
        ));

        return 0;
    }
}
