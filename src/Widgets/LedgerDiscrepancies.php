<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\Discrepancies;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\LedgerBreaks;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\PanelActor;
use Liberu\Ecommerce\InventoryLedger\Models\InventoryLocation;
use Liberu\Ecommerce\InventoryLedger\Models\StockLevel;

/**
 * Balances that disagree with their own history — the incident, not the symptom.
 *
 * A discrepancy nobody sees is a stock count nobody trusts. The check itself is
 * one line the runbook opens with; what was missing was somewhere it is
 * answered without being asked. So it sits on the dashboard, empty almost
 * always, and loud when it is not.
 *
 * Each row carries both numbers side by side — what the balance says, and what
 * the movements add up to — because the difference between them is the size of
 * the problem, and one number alone is only an accusation. It also carries
 * {@see LedgerBreaks::firstBreak()}: the id of the first movement whose
 * recorded balance stops tracking the running total, which is where the
 * runbook's investigation would have arrived after a SQL session and a column
 * of arithmetic done by hand.
 *
 * ponytail: recomputes the check on every render rather than caching it. A
 * cached integrity check is an integrity check that can be stale, and a stale
 * "everything agrees" is worse than no widget at all.
 */
class LedgerDiscrepancies extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return PanelActor::teamId() !== null;
    }

    public function table(Table $table): Table
    {
        $locationIds = InventoryLocation::query()->ownedBy(PanelActor::teamId())->pluck('id')->all();

        // Built before the closures below are defined, so capturing it by value
        // is exactly right — unlike a `fn () => $rows` written above the loop
        // that fills it, which hands back the empty array it started as.
        $rows = Discrepancies::forLocations($locationIds);
        $levelIds = array_keys($rows);

        return $table
            ->heading('Ledger integrity')
            ->description('Balances that do not equal the sum of their own movements. Something wrote one of these numbers without writing a movement.')
            ->query(fn () => StockLevel::query()->with(['item', 'location'])->whereKey($levelIds))
            ->emptyStateHeading('Every balance agrees with its ledger')
            ->emptyStateDescription('Each on-hand and reserved figure equals the sum of the movements that produced it.')
            ->columns([
                TextColumn::make('item.sku')
                    ->label('SKU')
                    ->placeholder('No SKU'),
                TextColumn::make('location.name')
                    ->label('Location'),
                TextColumn::make('on_hand')
                    ->label('On hand says')
                    ->numeric(),
                TextColumn::make('on_hand_from_ledger')
                    ->label('Ledger says')
                    ->state(fn (StockLevel $record): int => (int) ($rows[$record->getKey()]->on_hand_from_ledger ?? 0)),
                TextColumn::make('reserved')
                    ->label('Reserved says')
                    ->numeric(),
                TextColumn::make('reserved_from_ledger')
                    ->label('Ledger says')
                    ->state(fn (StockLevel $record): int => (int) ($rows[$record->getKey()]->reserved_from_ledger ?? 0)),
                TextColumn::make('first_break')
                    ->label('Diverges at')
                    ->placeholder('Nowhere — the trail adds up, the balance does not')
                    ->state(fn (StockLevel $record): ?string => self::divergence($record)),
            ])
            ->recordActions([
                Action::make('movementTrail')
                    ->label('Movement trail')
                    ->icon('heroicon-o-queue-list')
                    ->url(fn (StockLevel $record): string => StockMovementResource::getUrl('index', [
                        'tableFilters' => [
                            'inventory_item_id' => ['value' => $record->inventory_item_id],
                            'location_id' => ['value' => $record->location_id],
                        ],
                    ])),
            ])
            ->paginated(false);
    }

    /**
     * "Nowhere" is a real answer and a different fault.
     *
     * A trail whose running total tracks every recorded balance, under a
     * balance row that disagrees with the total, means nothing corrupted the
     * history — something wrote the balance after the last movement. Reporting
     * that as a blank cell would send the investigation to read rows that are
     * all correct.
     */
    private static function divergence(StockLevel $record): ?string
    {
        $id = LedgerBreaks::firstBreak((int) $record->inventory_item_id, (int) $record->location_id);

        return $id === null ? null : 'Movement #'.$id;
    }
}
