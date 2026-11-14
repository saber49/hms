<?php

namespace App\Console\Commands;

use App\Helpers\Realtime;
use App\Models\Tenant;
use App\Services\Mail\MailService;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Watches connected company mailboxes (mail_konten) for changes in the
 * first inbox page — new arrivals, deletions and read/unread flips made
 * from ANY client (this app, Outlook, a phone …). When the signature
 * differs from the last observation a realtime 'mail' hint is broadcast
 * on the tenant channel, so open sessions refresh within seconds instead
 * of waiting for the 60 s frontend poll.
 *
 * Runs every minute (see App\Console\Kernel). The first observation only
 * primes the signature — never broadcast — so a deploy or scheduler
 * restart cannot fire a broadcast storm at every connected client.
 *
 * Usage:
 *   php artisan mail:poll                  # all active tenants
 *   php artisan mail:poll --tenant=acme    # one tenant
 */
class PollMailboxes extends Command
{
    protected $signature = 'mail:poll
                            {--tenant= : Limit to a single tenant slug}';

    protected $description = 'Broadcast a realtime hint when a connected mailbox changes (arrival, delete, read flag)';

    public function handle(): int
    {
        $slug = $this->option('tenant');
        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::where('aktiv', true)->get();

        foreach ($tenants as $tenant) {
            try {
                TenantService::switchToTenant($tenant);
                if (!Schema::connection('tenant')->hasTable('mail_konten')) {
                    continue;
                }

                foreach (DB::connection('tenant')->table('mail_konten')->where('aktiv', true)->get() as $k) {
                    try {
                        $this->watch($k, (int) $tenant->id);
                    } catch (\Throwable $e) {
                        // One broken mailbox (expired OAuth, IMAP down) must
                        // never abort the sweep for the remaining accounts.
                        Log::debug('mail.poll.konto', ['konto' => $k->id, 'tenant' => $tenant->id, 'err' => mb_substr($e->getMessage(), 0, 200)]);
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('mail.poll.tenant', ['tenant' => $tenant->id, 'err' => mb_substr($e->getMessage(), 0, 200)]);
            } finally {
                TenantService::reset();
            }
        }
        return self::SUCCESS;
    }

    private function watch(object $k, int $tenantId): void
    {
        $r = MailService::driver($k)->listing('inbox', null, null, 30);
        $sig = md5(json_encode([
            $r['unread'] ?? 0,
            array_map(fn ($i) => ($i['id'] ?? '') . ':' . (!empty($i['seen']) ? 1 : 0), $r['items'] ?? []),
        ]));
        $key = 'mail:watch:' . $k->id;
        $last = Cache::get($key);
        Cache::put($key, $sig, now()->addDay());
        if ($last !== null && $last !== $sig) {
            Realtime::changed('mail', ['konto' => (int) $k->id, 'unread' => (int) ($r['unread'] ?? 0)], $tenantId);
        }
    }
}
