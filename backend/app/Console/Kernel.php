<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Weekly cleanup: delete saved PDFs older than 1 year + expired
        // share links. --force makes it actually delete (default is dry-run).
        $schedule->command('pdf:prune --days=365 --force')
            ->weeklyOn(0, '03:00')   // Sundays at 03:00 server time
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/pdf-prune.log'));

        // Daily sweep: open renewal requests + notify workers about expired
        // or soon-expiring documents (see App\Console\Commands\NotifyExpiringDocs).
        $schedule->command('docs:notify-expiring --days=14')
            ->dailyAt('07:30')
            ->withoutOverlapping()
            ->onOneServer();

        // Daily reminder to Dispo managers (owner + auftraege.dispo.*) about
        // upcoming shifts that are still unplanned. Repeats every morning
        // until everything is planned. See App\Console\Commands\NotifyUnplannedShifts.
        $schedule->command('dispo:notify-unplanned')
            ->dailyAt('07:00')       // every day at 07:00 server time
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/dispo-unplanned.log'));

        // Worker reminder ~15 minutes before shift start (in-app + device
        // push). Runs every 5 minutes; dedup via einsatz_reminders_sent.
        // See App\Console\Commands\RemindUpcomingShifts.
        $schedule->command('shifts:remind-upcoming --minutes=15')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/shift-reminders.log'));

        // Subscription/trial expiry reminders to tenant owners (bell + email)
        // at 14/7/3/1/0 days before end + one "expired" notice afterwards.
        // Dedup per period via tenants.settings JSON. See
        // App\Console\Commands\NotifySubscriptionExpiry.
        $schedule->command('subscription:notify-expiring')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/subscription-notices.log'));

        // Dispo alert (bell + email) when an assigned worker has NOT
        // clocked in although the planned shift start is >10 min ago —
        // so the dispatcher can call the worker. One alert per pair.
        // See App\Console\Commands\NotifyLateClockIns.
        $schedule->command('dispo:notify-late-clockins --grace=10')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/dispo-late-clockins.log'));

        // System watchdog: check-in broken for everyone (5xx in clockin.log
        // or nobody clocked in although shifts started) → one Dispo alert.
        // See App\Console\Commands\CheckClockInHealth.
        $schedule->command('zeit:clockin-health')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/clockin-health.log'));

        // Nightly consistency audit of the clock data (last 7 days) — new
        // rule violations go once to the Dispo managers.
        // See App\Console\Commands\AuditZeiterfassung / App\Support\ZeitAudit.
        $schedule->command('zeit:audit --days=7')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/zeit-audit.log'));

        // Worker end-of-shift reminders: 15 min before the end, then at the
        // end and after every 30-min extension ("beenden oder verlängern?").
        // Every minute for precise timing; dedup via zeiterfassung columns.
        // See App\Console\Commands\NotifyShiftEnding.
        $schedule->command('shifts:notify-ending --lead=15 --grace=30')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/shift-ending.log'));

        // Pauses end automatically once the shift's planned pause duration
        // is reached — the worker returns to 'aktiv' (+ push) even if he
        // forgets "Fortsetzen". See App\Console\Commands\PauseAutoResume.
        $schedule->command('shifts:auto-resume-pauses')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/pause-auto-resume.log'));

        // Lead-Modul automation (A8/A9/A12, task digest, DSGVO reminder) —
        // once each morning; idempotent, thresholds in crm_einstellungen.
        $schedule->command('crm:automation')
            ->dailyAt('06:45')
            ->timezone('Europe/Berlin')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/crm-automation.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
