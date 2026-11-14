<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off backfill: link site photos uploaded BEFORE mitarbeiter_nachrichten
 * got its einsatz_id column to the shift they were taken on.
 *
 * A photo is linked only when exactly ONE of the worker's shifts (yesterday
 * … same day) contains the upload time, with a 60-minute grace before the
 * start and after the end. Ambiguous or unmatched photos stay unlinked and
 * therefore never appear in the Kundenportal.
 *
 *   php artisan kundenportal:link-photos --dry
 *   php artisan kundenportal:link-photos
 */
class LinkPhotosToEinsatz extends Command
{
    protected $signature = 'kundenportal:link-photos {--tenant= : Limit to one tenant slug} {--dry : Report only}';
    protected $description = 'Link existing worker-app site photos to their Einsatz';

    private const GRACE_MINUTES = 60;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $tenants = Tenant::where('aktiv', true)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);
            $db = DB::connection('tenant');
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter_nachrichten', 'einsatz_id')) {
                $this->line("{$tenant->slug}: column missing — run tenant:migrate first");
                continue;
            }

            $photos = $db->table('mitarbeiter_nachrichten')
                ->whereNull('einsatz_id')->where('richtung', 'worker')
                ->where('datei_pfad', 'like', 'chat-fotos/%')->get();
            $linked = 0;
            foreach ($photos as $p) {
                $at = Carbon::parse($p->created_at);
                $day = $at->copy()->setTimezone('Europe/Berlin');
                $candidates = $db->table('einsatz_mitarbeiter as em')
                    ->join('einsaetze as e', 'e.einsatz_id', '=', 'em.einsatz_id')
                    ->where('em.dienst_nr', $p->dienst_nr)
                    ->whereBetween('e.datum', [$day->copy()->subDay()->toDateString(), $day->toDateString()])
                    ->pluck('e.einsatz_id');
                $hits = $candidates->filter(function ($eid) use ($p, $at) {
                    $w = \App\Support\ShiftWindow::fuer((string) $p->dienst_nr, (string) $eid);
                    return $w
                        && $at->gte($w[0]->copy()->subMinutes(self::GRACE_MINUTES))
                        && $at->lte($w[1]->copy()->addMinutes(self::GRACE_MINUTES));
                })->values();

                $status = $hits->count() === 1 ? 'link → ' . $hits[0] : ($hits->isEmpty() ? 'no shift' : 'ambiguous');
                $this->line("{$tenant->slug} #{$p->id} {$p->dienst_nr} {$day->format('d.m.Y H:i')}: {$status}");
                if ($hits->count() === 1) {
                    $linked++;
                    if (!$dry) $db->table('mitarbeiter_nachrichten')->where('id', $p->id)->update(['einsatz_id' => $hits[0]]);
                }
            }
            $this->info("{$tenant->slug}: {$linked}/{$photos->count()} photos " . ($dry ? 'linkable' : 'linked'));
        }
        return self::SUCCESS;
    }
}
