<?php

namespace App\Console\Commands;

use App\Helpers\NotificationDispatcher;
use App\Models\Tenant;
use App\Services\TenantService;
use App\Support\ZeitAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly consistency audit of the clock data (App\Support\ZeitAudit).
 * Rule violations found in the last --days days are sent ONCE to the Dispo
 * managers ('system.zeit_audit'); findings already reported in the last
 * 30 days are not repeated. Office corrections (EM_DIFF) and removed
 * assignments (EM_MISSING) are expected and only logged with -v.
 *
 *   php artisan zeit:audit [--tenant=slug] [--days=7] [--dry-run] [-v]
 */
class AuditZeiterfassung extends Command
{
    protected $signature = 'zeit:audit
                            {--tenant= : Limit to a single tenant slug}
                            {--days=7 : Audit shifts of the last N days}
                            {--dry-run : Report only; do not send notifications}';

    protected $description = 'Nightly consistency audit of the Zeiterfassung (alerts Dispo on new rule violations)';

    public function handle(): int
    {
        $days   = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $from   = Carbon::now('Europe/Berlin')->subDays($days)->toDateString();
        $slug   = $this->option('tenant');
        $tenants = $slug ? Tenant::where('slug', $slug)->get() : Tenant::where('aktiv', true)->get();

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);
            $db = DB::connection('tenant');
            if (!Schema::connection('tenant')->hasTable('zeiterfassung')
                || !Schema::connection('tenant')->hasTable('notifications')
                || !Schema::connection('tenant')->hasColumn('zeiterfassung', 'clock_in_real')) continue;

            $issues = ZeitAudit::run($from);
            $alerts = array_diff_key($issues, array_flip(ZeitAudit::INFO_CODES));
            if ($this->output->isVerbose()) {
                foreach ($issues as $code => $list) $this->line(sprintf('  %-28s %-16s %d', substr($tenant->slug, 0, 28), $code, count($list)));
            }
            if (!$alerts) continue;

            // Only findings not reported in the last 30 days.
            $known = [];
            $db->table('notifications')->where('type', 'system.zeit_audit')
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->pluck('payload')->each(function ($p) use (&$known) {
                    foreach ((json_decode((string) $p, true)['keys'] ?? []) as $k) $known[$k] = true;
                });
            $new = [];
            foreach ($alerts as $code => $list) {
                foreach ($list as $item) if (!isset($known[$item['key']])) $new[$code][] = $item;
            }
            if (!$new) continue;

            $count = array_sum(array_map('count', $new));
            $lines = [];
            foreach ($new as $code => $list) {
                foreach (array_slice($list, 0, 10) as $item) $lines[] = "[$code] " . $item['msg'];
                if (count($list) > 10) $lines[] = "[$code] … +" . (count($list) - 10) . ' weitere';
            }
            $this->line(sprintf('%s %-28s %d neue Befunde', Carbon::now()->toDateTimeString(), substr($tenant->slug, 0, 28), $count)
                . ($dryRun ? ' [DRY]' : ''));
            foreach ($lines as $l) $this->line('    ' . $l);
            if ($dryRun) continue;

            NotificationDispatcher::notifyDispoManagers(
                (int) $tenant->id,
                'system.zeit_audit',
                "Zeiterfassung prüfen: {$count} Unstimmigkeit(en)",
                "Die nächtliche Prüfung der Zeiterfassung (letzte {$days} Tage) hat Folgendes gefunden:\n"
                    . implode("\n", array_slice($lines, 0, 40)),
                '#monatsabschluss',
                ['keys' => array_values(array_merge(...array_map(fn ($l) => array_column($l, 'key'), array_values($new)))),
                 'from' => $from]
            );
        }

        TenantService::reset();
        return 0;
    }
}
