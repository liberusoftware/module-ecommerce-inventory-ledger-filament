<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource;

/**
 * No header actions at all — no create, because a movement is written by a
 * domain action and never by a form on this page.
 *
 * The subheading is not decoration. An operator arriving at a table of rows
 * with no edit and no delete needs to be told that is the design rather than
 * left to conclude the panel is broken or their permissions are wrong.
 */
class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    public function getSubheading(): string
    {
        return 'The ledger is append-only. Nothing here can be edited or deleted — a correction is a new movement, recorded from the row it corrects.';
    }
}
