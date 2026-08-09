<?php

use Filament\Facades\Filament;
use Liberu\Ecommerce\InventoryLedger\Filament\InventoryLedgerFilamentServiceProvider;
use Liberu\Ecommerce\InventoryLedger\Filament\InventoryLedgerPlugin;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockLevelResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockReservationResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Widgets\LedgerDiscrepancies;
use Liberu\Ecommerce\InventoryLedger\Filament\Widgets\StockOverview;

it('contributes its resources and widgets to the panel that attaches it', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getPlugin(InventoryLedgerPlugin::make()->getId()))->toBeInstanceOf(InventoryLedgerPlugin::class)
        ->and($panel->getResources())->toContain(
            StockMovementResource::class,
            StockLevelResource::class,
            StockReservationResource::class,
            InventoryLocationResource::class,
        )
        ->and($panel->getWidgets())->toContain(StockOverview::class, LedgerDiscrepancies::class);
});

it('declares in its manifest exactly the plugin and provider it ships', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, flags: JSON_THROW_ON_ERROR);

    $plugins = $manifest['presentation']['filament']['admin'];

    expect($plugins)->toBe([InventoryLedgerPlugin::class])
        ->and($manifest['provider'])->toBe(InventoryLedgerFilamentServiceProvider::class)
        ->and($manifest['category'])->toBe('presentation');

    foreach ($plugins as $plugin) {
        expect(class_exists($plugin))->toBeTrue();
    }
});

/**
 * The plugin lists what it contributes rather than scanning `src/` for it, which
 * is what makes `filament:cache-components` a no-op worth running and a boot
 * with no filesystem walk. The cost of a list is that a class added later is
 * simply absent from the panel — no error, no missing-file warning, just a
 * resource nobody can reach. This is the test that turns that silence into a
 * failure.
 */
it('registers exactly the resources and widgets the package ships', function () {
    $panel = Filament::getPanel('admin');
    $src = dirname(__DIR__, 2).'/src';

    $shipped = function (string $directory, string $namespace) use ($src): array {
        $classes = array_map(
            fn (string $path): string => $namespace.'\\'.basename($path, '.php'),
            (array) glob($src.'/'.$directory.'/*.php'),
        );

        sort($classes);

        return $classes;
    };

    $registered = function (array $classes): array {
        $ours = array_values(array_filter(
            $classes,
            fn (string $class): bool => str_starts_with($class, 'Liberu\\Ecommerce\\InventoryLedger\\Filament\\'),
        ));

        sort($ours);

        return $ours;
    };

    expect($registered($panel->getResources()))
        ->toBe($shipped('Resources', 'Liberu\\Ecommerce\\InventoryLedger\\Filament\\Resources'))
        ->and($registered($panel->getWidgets()))
        ->toBe($shipped('Widgets', 'Liberu\\Ecommerce\\InventoryLedger\\Filament\\Widgets'));
});

it('groups its navigation under one heading so it does not scatter through the panel', function () {
    $this->actorForTeam(7);

    foreach ([StockMovementResource::class, StockLevelResource::class, StockReservationResource::class, InventoryLocationResource::class] as $resource) {
        expect($resource::getNavigationGroup())->toBe('Inventory');
    }
});
