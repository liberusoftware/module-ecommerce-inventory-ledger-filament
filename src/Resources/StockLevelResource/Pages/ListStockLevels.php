<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockLevelResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockLevelResource;

/**
 * No create action: a balance appears the first time stock moves into a place,
 * written by `StockLedger` under the lock it holds. There is nothing to create.
 */
class ListStockLevels extends ListRecords
{
    protected static string $resource = StockLevelResource::class;

    public function getSubheading(): string
    {
        return 'Every number here is the sum of the movements behind it. Change one by recording a count or an adjustment; the balance follows the ledger, never the other way round.';
    }
}
