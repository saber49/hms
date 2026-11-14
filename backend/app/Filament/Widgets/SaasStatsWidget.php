<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SaasStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $tenants = Tenant::where('aktiv', true)->get();
        $paying = $tenants->filter(fn (Tenant $t) => $t->plan !== 'trial' && $t->isSubscriptionActive());
        $mrr = $paying->sum(fn (Tenant $t) => config("plans.plans.{$t->plan}.price_monthly", 0));

        $activeTrials = $tenants->filter(fn (Tenant $t) => $t->isTrialActive())->count();
        $expiringSoon = $tenants->filter(function (Tenant $t) {
            $end = $t->subscription_ends_at ?? $t->trial_ends_at;
            return $end && $end->isFuture() && $end->lte(now()->addDays(14));
        })->count();
        $expired = $tenants->filter(fn (Tenant $t) => ! $t->isSubscriptionActive())->count();

        return [
            Stat::make('MRR', number_format($mrr, 0, ',', '.') . ' €')
                ->description($paying->count() . ' zahlende Mandanten')
                ->color('success')
                ->icon('heroicon-o-banknotes'),
            Stat::make('Aktive Trials', $activeTrials)
                ->description('Testphase läuft')
                ->color('info')
                ->icon('heroicon-o-clock'),
            Stat::make('Läuft bald ab', $expiringSoon)
                ->description('Trial/Abo endet ≤ 14 Tage')
                ->color('warning')
                ->icon('heroicon-o-exclamation-triangle'),
            Stat::make('Abgelaufen (aktiv-Flag)', $expired)
                ->description('Kein gültiges Abo, noch aktiv')
                ->color($expired > 0 ? 'danger' : 'gray')
                ->icon('heroicon-o-no-symbol'),
        ];
    }
}
