<?php

use Liberu\Ecommerce\InventoryLedger\Actions\ReceiveStock;
use Liberu\Ecommerce\InventoryLedger\Actions\ReserveStock;
use Liberu\Ecommerce\InventoryLedger\Filament\Tests\TestCase;
use Liberu\Ecommerce\InventoryLedger\Models\InventoryItem;
use Liberu\Ecommerce\InventoryLedger\Models\InventoryLocation;
use Liberu\Ecommerce\InventoryLedger\Models\StockLevel;
use Liberu\Ecommerce\InventoryLedger\Models\StockReservation;

pest()->extend(TestCase::class)->in('Feature');

/**
 * The arrangement helpers, all of them going through the domain.
 *
 * Nothing here inserts a movement or a balance directly. A suite that seeded
 * balances by hand would be asserting against numbers no ledger produced, which
 * is exactly the corruption `LedgerIntegrity` exists to catch — so the tests
 * that need one deliberately reach past these, through the query builder, and
 * say so.
 */
function aLocation(int $teamId = 7, array $state = []): InventoryLocation
{
    return InventoryLocation::factory()->ownedBy($teamId)->create($state);
}

function anItem(array $state = []): InventoryItem
{
    return InventoryItem::factory()->create($state);
}

function stocked(InventoryItem $item, InventoryLocation $location, int $quantity): StockLevel
{
    app(ReceiveStock::class)->handle($item, $location, $quantity);

    return balanceOf($item, $location);
}

function held(InventoryItem $item, InventoryLocation $location, int $quantity): StockReservation
{
    return app(ReserveStock::class)->handle($item, $location, $quantity);
}

function balanceOf(InventoryItem $item, InventoryLocation $location): StockLevel
{
    return StockLevel::query()
        ->where('inventory_item_id', $item->getKey())
        ->where('location_id', $location->getKey())
        ->firstOrFail();
}
