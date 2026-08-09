<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\Discrepancies;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\PanelActor;
use Liberu\Ecommerce\InventoryLedger\Models\InventoryLocation;
use Liberu\Ecommerce\InventoryLedger\Models\StockLevel;
use Liberu\Ecommerce\InventoryLedger\Models\StockReservation;

/**
 * What the merchant is holding, and whether the numbers can be believed.
 *
 * The last stat is the one this widget exists for. `LedgerIntegrity` is the
 * module's whole argument made runnable — every balance equals the sum of its
 * own movements — and a check nobody runs is an assurance nobody has. Put on
 * the dashboard it is answered before anybody thinks to ask, which is the
 * difference between finding a corrupted balance and being told about it by a
 * customer.
 *
 * Scoped through locations, because that is where tenancy lives.
 */
class StockOverview extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return PanelActor::teamId() !== null;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $teamId = PanelActor::teamId();

        $locations = InventoryLocation::query()->ownedBy($teamId);
        $locationIds = $locations->clone()->pluck('id')->all();

        $levels = StockLevel::query()->whereIn('location_id', $locationIds);

        $held = StockReservation::query()->whereIn('location_id', $locationIds)->held()->count();
        $discrepancies = count(Discrepancies::forLocations($locationIds));

        return [
            Stat::make('Locations', (string) $locations->clone()->count())
                ->description($locations->clone()->where('is_active', true)->count().' active'),
            Stat::make('On hand', (string) (int) $levels->clone()->sum('on_hand'))
                ->description('across '.$levels->clone()->count().' balances'),
            Stat::make('Reserved', (string) (int) $levels->clone()->sum('reserved'))
                ->description($held.' holds still open'),
            // Said in words as well as in colour: "0 discrepancies" is the
            // assurance, and a reader who cannot see the green is entitled to
            // it too.
            Stat::make('Ledger integrity', $discrepancies === 0 ? 'Balances agree' : $discrepancies.' disagree')
                ->description($discrepancies === 0
                    ? 'Every balance equals the sum of its movements'
                    : 'Something wrote a balance without writing a movement')
                ->color($discrepancies === 0 ? 'success' : 'danger'),
        ];
    }
}
