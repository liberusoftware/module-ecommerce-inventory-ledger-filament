<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockLevelResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockReservationResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Widgets\LedgerDiscrepancies;
use Liberu\Ecommerce\InventoryLedger\Filament\Widgets\StockOverview;

/**
 * What this package contributes to a panel the application composes.
 *
 * Listed rather than discovered by directory scan: discovery reads the
 * filesystem on every boot to rediscover a set that is fixed at release, and a
 * scan rooted at `src/` would also sweep up anything a later version happens to
 * put there. The list is the same either way, and it is the one place to read
 * what attaching this plugin adds. `tests/Feature/PanelTest.php` fails the
 * build when a shipped class is missing from it, which is the only thing that
 * turns an unregistered resource from silence into an error.
 */
final class InventoryLedgerPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'liberu-ecommerce-inventory-ledger';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                StockMovementResource::class,
                StockLevelResource::class,
                StockReservationResource::class,
                InventoryLocationResource::class,
            ])
            ->widgets([
                StockOverview::class,
                LedgerDiscrepancies::class,
            ]);
    }

    public function boot(Panel $panel): void {}
}
