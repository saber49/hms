<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Mandanten';

    protected static ?string $modelLabel = 'Mandant';

    protected static ?string $pluralModelLabel = 'Mandanten';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('slug')->disabled(),
            TextInput::make('database')->disabled(),
            Select::make('plan')
                ->options(collect(config('plans.plans'))->mapWithKeys(
                    fn ($p, $key) => [$key => $p['name'] . ' (' . $p['price_monthly'] . ' €/Monat)']
                )->all())
                ->required(),
            Toggle::make('aktiv')->label('Aktiv'),
            DateTimePicker::make('trial_ends_at')->seconds(false),
            DateTimePicker::make('subscription_ends_at')->seconds(false),
            TextInput::make('max_workers')->numeric(),
            TextInput::make('max_customers')->numeric(),
            TextInput::make('max_users')->numeric(),
            TextInput::make('company_email')->email(),
            TextInput::make('company_phone'),
            Toggle::make('onboarding_completed'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->toggleable(),
                TextColumn::make('plan')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'trial' => 'gray',
                        'basic' => 'info',
                        'pro' => 'success',
                        'enterprise' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(function (Tenant $t): string {
                        if (! $t->aktiv) {
                            return 'deaktiviert';
                        }
                        if ($t->isSubscriptionActive()) {
                            return 'aktiv';
                        }
                        return 'abgelaufen';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'aktiv' => 'success',
                        'abgelaufen' => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('aktiv')->boolean()->label('Aktiv-Flag')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('trial_ends_at')
                    ->label('Trial bis')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn (Tenant $t) => $t->trial_ends_at?->isPast() ? 'danger' : null),
                TextColumn::make('subscription_ends_at')
                    ->label('Abo bis')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(function (Tenant $t) {
                        $end = $t->subscription_ends_at;
                        if (! $end) {
                            return null;
                        }
                        return $end->isPast() ? 'danger' : ($end->lte(now()->addDays(14)) ? 'warning' : null);
                    }),
                TextColumn::make('central_users_count')
                    ->label('Benutzer')
                    ->counts('centralUsers')
                    ->sortable(),
                TextColumn::make('created_at')->label('Erstellt')->date('d.m.Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('plan')
                    ->options(collect(config('plans.plans'))->mapWithKeys(fn ($p, $k) => [$k => $p['name']])->all()),
                TernaryFilter::make('aktiv')->label('Aktiv'),
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
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
