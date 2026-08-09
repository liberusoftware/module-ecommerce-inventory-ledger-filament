<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\InventoryLedger\Filament\InventoryLedgerPlugin;

/**
 * The panel this package's resources need in order to be resources at all.
 *
 * The package ships a plugin and the application composes the panel, so the
 * suite composes the smallest panel that attaches the plugin — under the `admin`
 * id `module.json`'s `presentation.filament` key names, which is the composition
 * this repository is actually claiming works.
 *
 * Deliberately not tenant-aware. The plugin must work on a panel with no
 * tenancy, because nothing in the manifest says the host's panel has any.
 */
final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugins([InventoryLedgerPlugin::make()]);
    }
}
