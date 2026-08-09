<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
use Liberu\Ecommerce\InventoryLedger\Enums\MovementReason;
use Liberu\Ecommerce\InventoryLedger\Enums\MovementType;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource\Pages\ListStockMovements;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\LedgerWrite;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\MovementFields;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\PanelActor;
use Liberu\Ecommerce\InventoryLedger\Models\InventoryItem;
use Liberu\Ecommerce\InventoryLedger\Models\StockMovement;
use UnitEnum;

/**
 * The trail, which is the centre of this package rather than a sub-tab.
 *
 * An operator comes here to answer one question — *why does this say 3 when the
 * shelf has 5* — and the only thing that answers it is the rows, in order, with
 * the balance each one recorded at the time. So the trail is a resource of its
 * own, filterable by item, location, type, reason and date, and every row shows
 * the `on_hand_after` and `available_after` it wrote.
 *
 * ## There is no edit action and no delete action, on purpose
 *
 * `StockMovement` refuses updates and deletes at the model. A resource
 * scaffolded from habit offers both, and both then fail with
 * `LedgerIsAppendOnly` — which is a worse experience than not offering them,
 * because the operator has already decided what to change by the time the
 * refusal arrives. So:
 *
 * - no `edit` page and no `EditAction`;
 * - no `DeleteAction`, no `DeleteBulkAction`;
 * - `canCreate()`, `canEdit()`, `canDelete()` and `canDeleteAny()` all say no
 *   out loud rather than leaving it to be inferred from the missing routes.
 *
 * Saying it out loud matters because there is no `StockMovement` policy at all
 * — `InventoryLedgerServiceProvider` registers one policy, for
 * `InventoryLocation` — and an ability nobody publishes is an ability the gate
 * has no opinion about. Every one of these would default permissive.
 *
 * What replaces them is {@see self::correctionAction()}: a correction is a
 * *new* movement with reason `Correction` and a note saying what was wrong. It
 * is the operation the operator actually wants, it is auditable, and it is
 * reversible by the same means.
 */
class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Movements';

    protected static ?int $navigationSort = 1;

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    /**
     * The team check the location policy would make, made here because no
     * policy covers this model. `viewAny` on a location is exactly *works in a
     * team*, and the query scope refuses everything else.
     */
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

    /** @return Builder<StockMovement> */
    public static function getEloquentQuery(): Builder
    {
        return PanelActor::scopeThroughLocation(parent::getEloquentQuery()->with(['item', 'location']));
    }

    /**
     * A movement has no form. It is written once, by an action, and never
     * edited — so the only schema this resource has is the correction's.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('item.sku')
                    ->label('SKU')
                    ->placeholder('No SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (MovementType $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->badge()
                    // Colour repeats what the word says rather than carrying
                    // it: shrinkage is the number a merchant is asked about,
                    // and it must be findable by somebody who cannot separate
                    // amber from grey.
                    ->color(fn (MovementReason $state): string => $state->isShrinkage() ? 'danger' : 'gray')
                    ->formatStateUsing(fn (MovementReason $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('on_hand_delta')
                    ->label('On-hand change')
                    ->formatStateUsing(fn (int $state): string => self::signed($state))
                    ->sortable(),
                TextColumn::make('reserved_delta')
                    ->label('Reserved change')
                    ->formatStateUsing(fn (int $state): string => self::signed($state))
                    ->sortable(),
                // The two columns the investigation is actually reading. They
                // are what the balance *was* the instant the row was written,
                // so walking them in order finds the moment the running total
                // and the recorded total part company.
                TextColumn::make('on_hand_after')
                    ->label('On hand after')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('available_after')
                    ->label('Available after')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reference')
                    ->label('For')
                    ->placeholder('Nothing named')
                    ->state(fn (StockMovement $record): ?string => $record->reference_type === null
                        ? null
                        : $record->reference_type.' #'.$record->reference_id),
                TextColumn::make('actor_id')
                    ->label('Actor')
                    ->placeholder('System')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('note')
                    ->label('Note')
                    ->placeholder('None')
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('inventory_item_id')
                    ->label('Item')
                    ->relationship('item', 'sku')
                    // `sku` is nullable, and a null option label is not a blank
                    // row in the dropdown — it is a type error inside Filament
                    // that takes the whole filter bar down with it. An item
                    // with no SKU is ordinary, so it gets a label rather than
                    // an exception.
                    ->getOptionLabelFromRecordUsing(fn (InventoryItem $record): string => $record->sku ?? 'Product #'.$record->product_id)
                    ->searchable()
                    ->preload(),
                SelectFilter::make('location_id')
                    ->label('Location')
                    // The options are the team's locations, for the same reason
                    // the rows are: a filter listing every merchant's warehouses
                    // tells you who else is on the estate.
                    ->relationship('location', 'name', fn (Builder $query): Builder => $query->ownedBy(PanelActor::teamId()))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(self::typeOptions()),
                SelectFilter::make('reason')
                    ->label('Reason')
                    ->options(MovementFields::options()),
                Filter::make('shrinkage')
                    ->label('Shrinkage only')
                    ->query(fn (Builder $query): Builder => $query->whereIn('reason', MovementFields::shrinkageValues())),
                Filter::make('recorded')
                    ->label('Recorded between')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $scoped, string $from): Builder => $scoped->whereDate('created_at', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $scoped, string $until): Builder => $scoped->whereDate('created_at', '<=', $until))),
            ])
            ->recordActions([
                self::correctionAction(),
            ])
            // Oldest first, and by id rather than by `created_at`: several
            // movements written in one transaction share a timestamp to the
            // second, and the order they happened in is the entire reason
            // somebody is reading them.
            ->defaultSort('id');
    }

    /**
     * The only write this resource offers, and it appends rather than edits.
     *
     * `AdjustStock` with `MovementReason::Correction`, which the domain refuses
     * without a note — so the note is required here rather than discovered to
     * be required after the operator has typed the number and lost it.
     *
     * Authorized with `moveStock` against the movement's *location*, which is
     * the ability the domain publishes for writing stock, and which an inactive
     * location refuses.
     */
    public static function correctionAction(): Action
    {
        return Action::make('recordCorrection')
            ->label('Record a correction')
            ->icon('heroicon-o-plus-circle')
            ->modalHeading('Record a correction')
            ->modalDescription('The ledger is append-only. This writes a new movement against the same item and location — it does not change the row you are looking at.')
            ->modalSubmitActionLabel('Record it')
            ->visible(fn (StockMovement $record): bool => Gate::allows('moveStock', $record->location))
            ->schema([
                TextInput::make('delta')
                    ->label('Change to on hand')
                    ->helperText('Signed. 2 puts two back on the shelf, -2 takes two off it. Zero is not a correction.')
                    ->integer()
                    ->required(),
                MovementFields::note()
                    ->required()
                    ->helperText('What was wrong, and how you know. A correction with no note is a row nobody can ever investigate.'),
            ])
            ->action(function (StockMovement $record, array $data): void {
                LedgerWrite::attempt(
                    fn () => app(AdjustStock::class)->handle(
                        $record->item,
                        $record->location,
                        (int) $data['delta'],
                        MovementReason::Correction,
                        PanelActor::attribution((string) $data['note']),
                    ),
                    'Correction recorded',
                );
            });
    }

    /** @return array<string, string> */
    private static function typeOptions(): array
    {
        $options = [];

        foreach (MovementType::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    private static function signed(int $delta): string
    {
        return $delta > 0 ? '+'.$delta : (string) $delta;
    }

    /**
     * Index only. There is no create page because nothing creates a movement
     * from a form, and no edit page because nothing edits one at all.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
