<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\InventoryLedger\Actions\ReleaseReservation;
use Liberu\Ecommerce\InventoryLedger\Enums\ReservationStatus;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockReservationResource\Pages\ListStockReservations;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\LedgerWrite;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\PanelActor;
use Liberu\Ecommerce\InventoryLedger\Models\StockReservation;
use UnitEnum;

/**
 * The other half of "why does this say 3 when the shelf has 5".
 *
 * `available` is `on_hand - reserved`, so a shelf full of stock can be entirely
 * spoken for and a shop can refuse to sell something it is standing on. The
 * runbook's most common cause of that is a sweep that is not running, which
 * shows up here as a pile of holds whose `expires_at` is in the past — hence
 * the *Overdue* filter, which is the same `expired()` scope
 * `ReleaseExpiredReservations` sweeps with.
 *
 * A hold is all-or-nothing, so releasing offers no quantity: releasing half of
 * one would be a second decision about the same stock wearing the first one's
 * audit trail. There is no create action either — stock is held by whatever
 * asked for it, through `ReserveStock`, and a hold typed into a panel is a hold
 * with no basket behind it that nothing will ever give back.
 */
class StockReservationResource extends Resource
{
    protected static ?string $model = StockReservation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Holds';

    protected static ?int $navigationSort = 3;

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

    /** @return Builder<StockReservation> */
    public static function getEloquentQuery(): Builder
    {
        return PanelActor::scopeThroughLocation(parent::getEloquentQuery()->with(['item', 'location']));
    }

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
                TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Held')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ReservationStatus $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('reference')
                    ->label('For')
                    ->placeholder('Nothing named')
                    ->state(fn (StockReservation $record): ?string => $record->reference_type === null
                        ? null
                        : $record->reference_type.' #'.$record->reference_id),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    // A hold with no expiry is legitimate — an allocation
                    // against a paid order — and wrong for a basket. Said in
                    // words, because an empty cell says neither.
                    ->placeholder('Held until released')
                    ->dateTime()
                    ->description(fn (StockReservation $record): ?string => $record->hasExpired() ? 'Overdue — the sweep has not given this back' : null)
                    ->sortable(),
                TextColumn::make('released_at')
                    ->label('Released')
                    ->placeholder('Still open')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
                SelectFilter::make('location_id')
                    ->label('Location')
                    ->relationship('location', 'name', fn (Builder $query): Builder => $query->ownedBy(PanelActor::teamId()))
                    ->searchable()
                    ->preload(),
                Filter::make('overdue')
                    ->label('Overdue — held past their time')
                    // The sweep's own scope, not a second copy of the same
                    // comparison. A null `expires_at` is *hold until released*
                    // and is excluded there deliberately; restating the
                    // condition here is how the two would come to disagree.
                    ->query(fn (Builder $query): Builder => $query->expired()),
            ])
            ->recordActions([
                self::releaseAction(),
            ])
            ->defaultSort('id');
    }

    /**
     * Give a hold back, through the domain action that writes the movement.
     *
     * Authorized with `holdStock` rather than `moveStock`: an inactive location
     * still permits releasing, or deactivating a location would strand every
     * basket pointing at it.
     *
     * Hidden on a hold that is already over. The action is idempotent for a
     * released one and throws for a committed one, and offering a button whose
     * only outcomes are "nothing happened" and "an error" is worse than not
     * offering it.
     */
    public static function releaseAction(): Action
    {
        return Action::make('releaseHold')
            ->label('Release')
            ->icon('heroicon-o-lock-open')
            ->requiresConfirmation()
            ->modalHeading('Release this hold')
            ->modalDescription('The stock goes back to available-to-sell and the ledger records that it did. A hold is all-or-nothing, so this releases the whole of it.')
            ->modalSubmitActionLabel('Release it')
            ->visible(fn (StockReservation $record): bool => $record->status->isHolding()
                && Gate::allows('holdStock', $record->location))
            ->action(function (StockReservation $record): void {
                LedgerWrite::attempt(
                    fn () => app(ReleaseReservation::class)->handle($record, PanelActor::attribution()),
                    'Hold released',
                );
            });
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (ReservationStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStockReservations::route('/'),
        ];
    }
}
