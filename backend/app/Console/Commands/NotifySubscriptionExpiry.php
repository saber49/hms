<?php

namespace App\Console\Commands;

use App\Helpers\NotificationDispatcher;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Daily reminder to tenant OWNERS that their subscription (or trial) is
 * about to expire — or has just expired.
 *
 * Stages (days remaining until trial_ends_at / subscription_ends_at):
 *   d14, d7, d3, d1, d0 (expires today) and 'expired' (once, after expiry).
 *
 * Each stage fires at most ONCE per subscription period. Dedup marker is
 * kept in the central tenants.settings JSON under 'subscription_notice'
 * as "<period-end>|<stage>" — renewing (i.e. a new end date) automatically
 * re-arms all stages for the next period.
 *
 * Delivery = in-app bell notification (tenant notifications table) + styled
 * HTML email, both to owners only, via NotificationDispatcher::notifyOwners().
 * Note: after expiry the API blocks tenant requests with 402, so the bell is
 * not visible any more — the email is then the effective channel.
 *
 * Usage:
 *   php artisan subscription:notify-expiring             # all active tenants
 *   php artisan subscription:notify-expiring --tenant=acme
 *   php artisan subscription:notify-expiring --dry-run   # report only
 */
class NotifySubscriptionExpiry extends Command
{
    protected $signature = 'subscription:notify-expiring
                            {--tenant= : Limit to a single tenant slug}
                            {--dry-run : Report only; do not send notifications/emails}';

    protected $description = 'Notify tenant owners about expiring or expired subscriptions (bell + email)';

    /** Reminder thresholds in days-before-expiry, largest first. */
    private const STAGES = [14, 7, 3, 1, 0];

    /** How many days after expiry the one-time "expired" notice may still fire. */
    private const EXPIRED_GRACE_DAYS = 14;

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

        $notified = 0;

        foreach ($tenants as $tenant) {
            $end = $tenant->plan === 'trial'
                ? $tenant->trial_ends_at
                : $tenant->subscription_ends_at;

            if (!$end) {
                continue;
            }

            // Whole days remaining (negative = already expired).
            $daysLeft = Carbon::today()->diffInDays($end->copy()->startOfDay(), false);

            $stage = $this->resolveStage((int) $daysLeft);
            if ($stage === null) {
                continue;
            }

            $marker  = $end->toDateString() . '|' . $stage;
            $settings = $tenant->settings ?? [];
            $already  = $settings['subscription_notice'] ?? null;

            // Same stage for the same period already sent → skip. Earlier
            // stages are implicitly superseded by later ones (d7 after d14 …).
            if ($already !== null && str_starts_with((string) $already, $end->toDateString() . '|')) {
                $sentStage = substr((string) $already, strlen($end->toDateString()) + 1);
                if ($this->stageRank($stage) <= $this->stageRank($sentStage)) {
                    continue;
                }
            }

            $this->line(sprintf(
                '  %-30s plan=%-10s ends=%s stage=%s%s',
                substr($tenant->slug, 0, 30),
                $tenant->plan,
                $end->toDateString(),
                $stage,
                $dryRun ? '  [dry-run]' : ''
            ));

            if ($dryRun) {
                continue;
            }

            $sent = $this->notifyTenant($tenant, $stage, (int) $daysLeft, $end);

            if ($sent > 0) {
                $settings['subscription_notice'] = $marker;
                $tenant->settings = $settings;
                $tenant->save();
                $notified++;
            }
        }

        TenantService::reset();

        $this->info(sprintf(
            '%s%d tenant(s) notified.',
            $dryRun ? '[DRY-RUN] ' : '',
            $notified
        ));

        return 0;
    }

    /**
     * Map days-remaining to a reminder stage, or null when nothing is due.
     */
    private function resolveStage(int $daysLeft): ?string
    {
        if ($daysLeft < 0) {
            return -$daysLeft <= self::EXPIRED_GRACE_DAYS ? 'expired' : null;
        }

        foreach (self::STAGES as $threshold) {
            if ($daysLeft === $threshold) {
                return 'd' . $threshold;
            }
        }

        // Also catch "in between" days below the largest threshold when a
        // stage was missed (e.g. scheduler downtime): fire the tightest
        // threshold that is >= daysLeft so the owner still gets warned.
        foreach (array_reverse(self::STAGES) as $threshold) {
            if ($daysLeft < $threshold) {
                return 'd' . $threshold;
            }
        }

        return null;
    }

    /** Higher rank = more urgent. Used so a later stage supersedes an earlier one. */
    private function stageRank(string $stage): int
    {
        return match ($stage) {
            'd14'     => 1,
            'd7'      => 2,
            'd3'      => 3,
            'd1'      => 4,
            'd0'      => 5,
            'expired' => 6,
            default   => 0,
        };
    }

    /**
     * Build title/body/email and fan out to the owners. Returns recipients count.
     */
    private function notifyTenant(Tenant $tenant, string $stage, int $daysLeft, Carbon $end): int
    {
        $expired   = $stage === 'expired';
        $endDate   = $end->format('d.m.Y');
        $planLabel = ucfirst($tenant->plan);

        if ($expired) {
            $title = 'Ihr Abonnement ist abgelaufen';
            $body  = "Das {$planLabel}-Abonnement ist am {$endDate} abgelaufen."
                . "\nDer Zugriff auf das System ist gesperrt, Ihre Daten bleiben erhalten."
                . "\nBitte verlängern Sie Ihr Abonnement, um wieder Zugriff zu erhalten.";
        } elseif ($daysLeft === 0) {
            $title = 'Ihr Abonnement läuft heute ab';
            $body  = "Das {$planLabel}-Abonnement läuft heute ({$endDate}) ab."
                . "\nOhne Verlängerung wird der Zugriff danach gesperrt.";
        } else {
            $tage  = $daysLeft === 1 ? '1 Tag' : "{$daysLeft} Tagen";
            $title = "Ihr Abonnement läuft in {$tage} ab";
            $body  = "Das {$planLabel}-Abonnement läuft in {$tage} ({$endDate}) ab."
                . "\nBitte verlängern Sie rechtzeitig, damit der Zugriff nicht unterbrochen wird.";
        }

        $htmlBody = null;
        try {
            $htmlBody = view('emails.subscription-expiry', [
                'company'      => $tenant->name ?: 'Ihr Unternehmen',
                'planLabel'    => $planLabel,
                'endDate'      => $endDate,
                'daysLeft'     => max(0, $daysLeft),
                'expired'      => $expired,
                'billingEmail' => config('mail.from.address', 'info@clood-x.com'),
            ])->render();
        } catch (\Throwable $e) {
            Log::warning('subscription-expiry template render failed: ' . $e->getMessage());
        }

        // Switch to the tenant so the in-app row lands in the right DB.
        try {
            TenantService::switchToTenant($tenant);
        } catch (\Throwable $e) {
            Log::warning('subscription notice: tenant switch failed', [
                'tenant' => $tenant->slug,
                'error'  => $e->getMessage(),
            ]);
            return 0;
        }

        if (!Schema::connection('tenant')->hasTable('notifications')) {
            return 0;
        }

        return NotificationDispatcher::notifyOwners(
            (int) $tenant->id,
            $expired ? 'subscription.expired' : 'subscription.expiring',
            $title,
            $body,
            '#settings',
            [
                'stage'     => $stage,
                'days_left' => $daysLeft,
                'ends_at'   => $end->toDateString(),
                'plan'      => $tenant->plan,
            ],
            $htmlBody
        );
    }
}
