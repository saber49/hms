<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TenantResource;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Tenants needing attention: expired subscription but still active,
 * or trial/subscription ending within the next 14 days.
 */
class RiskTenantsTable extends TableWidget
{
    protected static ?string $heading = 'Risiko-Mandanten (Handlungsbedarf)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Tenant::query()
                    ->where('aktiv', true)
                    ->where(function ($q) {
                        $q->where(function ($q2) {
                            // Ending within 14 days
                            $q2->whereBetween('subscription_ends_at', [now(), now()->addDays(14)])
                                ->orWhereBetween('trial_ends_at', [now(), now()->addDays(14)]);
                        })->orWhere(function ($q2) {
                            // Expired but still flagged active
                            $q2->where('subscription_ends_at', '<', now())
                                ->orWhere(function ($q3) {
                                    $q3->where('plan', 'trial')->where('trial_ends_at', '<', now());
                                });
                        });
                    })
            )
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('plan')->badge(),
                TextColumn::make('trial_ends_at')->label('Trial bis')->date('d.m.Y')
                    ->color(fn (Tenant $t) => $t->trial_ends_at?->isPast() ? 'danger' : 'warning'),
                TextColumn::make('subscription_ends_at')->label('Abo bis')->date('d.m.Y')
                    ->color(fn (Tenant $t) => $t->subscription_ends_at?->isPast() ? 'danger' : 'warning'),
                TextColumn::make('days_left')
                    ->label('Tage übrig')
                    ->getStateUsing(function (Tenant $t) {
                        $end = $t->subscription_ends_at ?? $t->trial_ends_at;
                        if (! $end) {
                            return '—';
                        }
                        $d = (int) now()->diffInDays($end, false);
                        return $d < 0 ? 'abgelaufen (' . abs($d) . 'd)' : $d . ' Tage';
                    })
                    ->badge()
                    ->color(fn (Tenant $t) => ($t->subscription_ends_at ?? $t->trial_ends_at)?->isPast() ? 'danger' : 'warning'),
                TextColumn::make('company_email')->label('Kontakt'),
            ])
            ->recordActions([
                Action::make('aboVerlaengern')
                    ->label('Abo +12 Monate')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Abo verlängern')
                    ->modalDescription(fn (Tenant $t) => 'Neues Abo-Ende: '
                        . (($t->subscription_ends_at?->isFuture() ? $t->subscription_ends_at : now())->copy()->addYear()->format('d.m.Y')))
                    ->action(function (Tenant $record) {
                        $base = $record->subscription_ends_at?->isFuture() ? $record->subscription_ends_at : now();
                        $record->update(['subscription_ends_at' => $base->copy()->addYear()]);
                        Notification::make()
                            ->title('Abo verlängert bis ' . $record->subscription_ends_at->format('d.m.Y'))
                            ->success()->send();
                    })
                    ->visible(fn (Tenant $t) => $t->plan !== 'trial'),
                Action::make('trialVerlaengern')
                    ->label('Trial +14 Tage')
                    ->icon('heroicon-o-clock')
                    ->requiresConfirmation()
                    ->modalHeading('Trial verlängern')
                    ->action(function (Tenant $record) {
                        $base = $record->trial_ends_at?->isFuture() ? $record->trial_ends_at : now();
                        $record->update(['trial_ends_at' => $base->copy()->addDays(14)]);
                        Notification::make()
                            ->title('Trial verlängert bis ' . $record->trial_ends_at->format('d.m.Y'))
                            ->success()->send();
                    })
                    ->visible(fn (Tenant $t) => $t->plan === 'trial'),
            ])
            ->recordUrl(fn (Tenant $t) => TenantResource::getUrl('edit', ['record' => $t]))
            ->paginated(false);
    }
}
