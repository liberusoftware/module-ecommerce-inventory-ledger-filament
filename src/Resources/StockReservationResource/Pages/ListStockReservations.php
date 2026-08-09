<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockReservationResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockReservationResource;

/**
 * No create action: stock is held by whatever asked for it, and a hold typed in
 * here has no basket behind it to ever give it back.
 */
class ListStockReservations extends ListRecords
{
    protected static string $resource = StockReservationResource::class;

    public function getSubheading(): string
    {
        return 'Stock that is on the shelf but spoken for. A pile of overdue holds is usually the expiry sweep not running, not an inventory problem.';
    }
}
