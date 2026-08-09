<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\InventoryLedger\Actions\AdjustStock;
use Liberu\Ecommerce\InventoryLedger\Actions\RecordCount;
use Liberu\Ecommerce\InventoryLedger\Enums\MovementReason;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockLevelResource\Pages\ListStockLevels;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\LedgerWrite;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\MovementFields;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\PanelActor;
use Liberu\Ecommerce\InventoryLedger\Models\StockLevel;
use UnitEnum;

/**
 * How many, and where.
 *
 * Read-only, and that is not a limitation — it is the invariant. A balance
 * holds no truth of its own: it is the sum of the movements that produced it,
 * and `LedgerIntegrity::discrepancies()` exists to say so. A form that
 * incremented `on_hand` here would write a number with no movement behind it,
 * which is precisely the corruption the integrity check is looking for and the
 * one thing the whole module is built to make impossible.
 *
 * So there is no create page, no edit page and no delete action. What there is
 * instead is the two things an operator standing in front of a disagreeing
 * shelf actually needs, both of which go through the domain and both of which
 * leave a row behind:
 *
 * - **Record a count** — {@see RecordCount}, the honest fix for "we have 40 and
 *   the system says 37". It takes the number seen and writes the variance as
 *   evidence, and writes a row even when the variance is zero.
 * - **Adjust** — {@see AdjustStock}, for a change whose reason is known. The
 *   reasons are the enum's, and the ones that demand a note demand it here.
 *
 * There is no `StockLevel` policy, so every ability is unpublished and would
 * default permissive. `canViewAny()` restates the location policy's `viewAny`
 * and the query scope refuses everything else.
 */
class StockLevelResource extends Resource
{
    protected static ?string $model = StockLevel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Balances';

    protected static ?int $navigationSort = 2;

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return PanelActor::teamId() !== null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return Builder<StockLevel> */
    public static function getEloquentQuery(): Builder
    {
        return PanelActor::scopeThroughLocation(parent::getEloquentQuery()->with(['item', 'location']));
    }

    /** A balance has no form. Every write goes through an action. */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item.sku')
                    ->label('SKU')
                    ->placeholder('No SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('item.product_id')
                    ->label('Product')
                    ->sortable(),
                TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),
                TextColumn::make('on_hand')
                    ->label('On hand')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reserved')
                    ->label('Reserved')
                    ->numeric()
                    ->sortable(),
                // Derived here exactly as it is derived everywhere else — by
                // asking the model. A second subtraction written in a column
                // callback is a second answer that can disagree with the first.
                TextColumn::make('available')
                    ->label('Available')
                    ->state(fn (StockLevel $record): int => $record->available())
                    ->description(fn (StockLevel $record): string => $record->reserved === 0
                        ? 'Nothing held'
                        : $record->reserved.' held for somebody'),
            ])
            ->filters([
                SelectFilter::make('location_id')
                    ->label('Location')
                    ->relationship('location', 'name', fn (Builder $query): Builder => $query->ownedBy(PanelActor::teamId()))
                    ->searchable()
                    ->preload(),
                Filter::make('held')
                    ->label('Something held')
                    ->query(fn (Builder $query): Builder => $query->where('reserved', '>', 0)),
                Filter::make('nothing_available')
                    ->label('Nothing available to sell')
                    ->query(fn (Builder $query): Builder => $query->whereRaw('on_hand - reserved <= 0')),
            ])
            ->recordActions([
                self::trailAction(),
                self::countAction(),
                self::adjustAction(),
            ])
            ->defaultSort('id');
    }

    /**
     * The link that makes this package worth attaching: from a number that
     * looks wrong to the rows that produced it, already filtered to that one
     * balance.
     *
     * The runbook's answer to "we have 40 and the system says 37" opens with a
     * `select … where inventory_item_id = ? and location_id = ?`. This is that
     * query, as a link.
     */
    public static function trailAction(): Action
    {
        return Action::make('movementTrail')
            ->label('Movement trail')
            ->icon('heroicon-o-queue-list')
            ->url(fn (StockLevel $record): string => StockMovementResource::getUrl('index', [
                'tableFilters' => [
                    'inventory_item_id' => ['value' => $record->inventory_item_id],
                    'location_id' => ['value' => $record->location_id],
                ],
            ]));
    }

    /**
     * A physical count, which is the only honest way to make the system agree
     * with a shelf.
     *
     * The absolute number seen, not a delta: the operator counted 40, they did
     * not compute +3. `RecordCount` takes the difference under the lock, which
     * is the only place the balance it is differencing from is stable.
     */
    public static function countAction(): Action
    {
        return Action::make('recordCount')
            ->label('Record a count')
            ->icon('heroicon-o-clipboard-document-check')
            ->modalHeading('Record a physical count')
            ->modalDescription('The number actually on the shelf. The variance against the balance is written to the ledger as evidence — including a variance of zero, which is the row that says the number was checked.')
            ->modalSubmitActionLabel('Record it')
            ->visible(fn (StockLevel $record): bool => Gate::allows('moveStock', $record->location))
            ->schema([
                TextInput::make('counted')
                    ->label('Counted on the shelf')
                    ->integer()
                    ->minValue(0)
                    ->required(),
                MovementFields::note()
                    ->helperText('Optional for a count. Worth writing when the variance is large enough that somebody will ask.'),
            ])
            ->action(function (StockLevel $record, array $data): void {
                LedgerWrite::attempt(
                    fn () => app(RecordCount::class)->handle(
                        $record->item,
                        $record->location,
                        (int) $data['counted'],
                        PanelActor::attribution($data['note'] ?? null),
                    ),
                    'Count recorded',
                );
            });
    }

    /**
     * A change whose reason is known, in either direction.
     *
     * The reason select is driven by `MovementReason::cases()` and the note is
     * required by `requiresNote()` — see {@see MovementFields} for why neither
     * is a list kept here.
     */
    public static function adjustAction(): Action
    {
        return Action::make('adjustStock')
            ->label('Adjust')
            ->icon('heroicon-o-pencil-square')
            ->modalHeading('Adjust the balance')
            ->modalDescription('Writes a movement. The balance moves because the ledger did, never the other way round.')
            ->modalSubmitActionLabel('Record it')
            ->visible(fn (StockLevel $record): bool => Gate::allows('moveStock', $record->location))
            ->schema([
                TextInput::make('delta')
                    ->label('Change to on hand')
                    ->helperText('Signed. 2 puts two back on the shelf, -2 takes two off it. Zero is not an adjustment — record a count instead.')
                    ->integer()
                    ->required(),
                MovementFields::reason(),
                MovementFields::note(),
            ])
            ->action(function (StockLevel $record, array $data): void {
                LedgerWrite::attempt(
                    fn () => app(AdjustStock::class)->handle(
                        $record->item,
                        $record->location,
                        (int) $data['delta'],
                        MovementReason::from((string) $data['reason']),
                        PanelActor::attribution($data['note'] ?? null),
                    ),
                    'Adjustment recorded',
                );
            });
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStockLevels::route('/'),
        ];
    }
}
