<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CentralUserResource\Pages;
use App\Models\CentralUser;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CentralUserResource extends Resource
{
    protected static ?string $model = CentralUser::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Registrierungen';

    protected static ?string $modelLabel = 'Registrierungs-Account';

    protected static ?string $pluralModelLabel = 'Registrierungs-Accounts';

    /**
     * Only the account that registered the company (is_owner) is shown here.
     * Staff users of a tenant are intentionally hidden — tenant-internal
     * user management belongs to the tenant, not the platform operator.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('is_owner', true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('tenant.name')->label('Firma')->disabled(),
            TextInput::make('email')->email()->required(),
            TextInput::make('full_name')->label('Name'),
            Toggle::make('aktiv')->label('Aktiv'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tenant.name')->label('Firma')->sortable()->searchable(),
                TextColumn::make('email')->label('Registrierungs-E-Mail')->searchable()->sortable(),
                TextColumn::make('tenant.company_email')->label('Firmen-E-Mail')->toggleable(),
                IconColumn::make('aktiv')->boolean(),
                IconColumn::make('email_verified_at')
                    ->label('Verifiziert')
                    ->boolean()
                    ->getStateUsing(fn (CentralUser $u) => $u->email_verified_at !== null),
                TextColumn::make('last_login')->label('Letzter Login')->dateTime('d.m.Y H:i')->sortable()->toggleable(),
                TextColumn::make('created_at')->label('Registriert am')->date('d.m.Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('tenant_id')
                    ->label('Firma')
                    ->relationship('tenant', 'name'),
                TernaryFilter::make('aktiv'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCentralUsers::route('/'),
            'edit' => Pages\EditCentralUser::route('/{record}/edit'),
        ];
    }
}
