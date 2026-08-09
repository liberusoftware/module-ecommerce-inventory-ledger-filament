<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Resources;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource\Pages\CreateInventoryLocation;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource\Pages\EditInventoryLocation;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource\Pages\ListInventoryLocations;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\PanelActor;
use Liberu\Ecommerce\InventoryLedger\Models\InventoryLocation;
use UnitEnum;

/**
 * Somewhere stock is held — and the one thing in this module a panel may
 * genuinely create.
 *
 * This is where tenancy lives. Every balance, movement and hold is reached
 * through a location, so `InventoryLocationPolicy` is the only policy the
 * domain registers and every other resource here scopes through this table. It
 * is also the only resource whose abilities are all published: `viewAny`,
 * `view`, `create`, `update` and `delete` are answered by a real policy rather
 * than restated here.
 *
 * `team_id` is not a form field. It is the tenant key the policy authorizes
 * against, and a location created into a team the operator does not work in is
 * one they immediately cannot use — see {@see CreateInventoryLocation}.
 *
 * Deleting cascades away the movements, which is the audit trail for everything
 * that ever happened there. `InventoryLocationPolicy::delete()` therefore
 * permits it only for a location that never held anything; the rest are
 * deactivated, which stops new stock arriving and keeps the history. Filament's
 * `DeleteAction` asks that policy, so the button disappears on its own — this
 * resource adds no rule of its own about it.
 */
class InventoryLocationResource extends Resource
{
    protected static ?string $model = InventoryLocation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Locations';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    /**
     * The domain's own scope, which already guards the null team with
     * `whereRaw('1 = 0')` — `where('team_id', null)` compiles to `is null` and
     * would list exactly the unowned locations the policy denies everything on.
     *
     * @return Builder<InventoryLocation>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ownedBy(PanelActor::teamId());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Code')
                ->helperText('Unique across the estate. What integrations and stock reports will name this place.')
                ->required()
                ->maxLength(255),
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),
            Toggle::make('is_active')
                ->label('Active')
                ->helperText('An inactive location keeps its stock and its history, and refuses new movements. Existing holds can still be released, so deactivating strands no baskets.')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                // A badge with words, not a tick. A tick and a cross are the
                // same to a screen reader, and the same to anybody who cannot
                // separate the two colours they are drawn in.
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('levels_count')
                    ->label('Balances')
                    ->counts('levels'),
                TextColumn::make('movements_count')
                    ->label('Movements')
                    ->counts('movements'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All locations')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('code');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryLocations::route('/'),
            'create' => CreateInventoryLocation::route('/create'),
            'edit' => EditInventoryLocation::route('/{record}/edit'),
        ];
    }
}
