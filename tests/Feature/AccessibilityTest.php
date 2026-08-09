<?php

use Filament\Actions\Action;
use Liberu\Ecommerce\InventoryLedger\Actions\AdjustStock;
use Liberu\Ecommerce\InventoryLedger\Enums\MovementReason;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource\Pages\ListInventoryLocations;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockLevelResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockLevelResource\Pages\ListStockLevels;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource\Pages\ListStockMovements;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockReservationResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockReservationResource\Pages\ListStockReservations;
use Liberu\Ecommerce\InventoryLedger\Values\Attribution;
use Livewire\Livewire;

// What a keyboard and a screen reader can get out of these surfaces. The two
// things worth a test are the two that regress silently: a state rendered as
// colour or as an icon with no words, and an action whose only affordance is an
// icon with no accessible name. Both look correct in a screenshot.

it('renders a movement\'s type and reason as words and not only as badge colours', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 10);
    app(AdjustStock::class)->handle($item, $location, -1, MovementReason::Damaged, Attribution::system('Crushed.'));

    Livewire::test(ListStockMovements::class)
        ->assertOk()
        ->assertSee('Receipt')
        ->assertSee('Purchase')
        // Shrinkage is drawn in a warning colour, and the colour repeats the
        // word rather than carrying it.
        ->assertSee('Damaged');
});

it('renders a hold\'s status as words', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 10);
    held($item, $location, 2);

    Livewire::test(ListStockReservations::class)
        ->assertOk()
        ->assertSee('Held');
});

it('says in words whether a location is active', function () {
    $this->actorForTeam(7);

    aLocation(7, ['name' => 'Open Depot']);
    aLocation(7, ['name' => 'Shut Depot', 'is_active' => false]);

    // A tick and a cross are the same to a screen reader, and the same to
    // anybody who cannot separate the two colours they are drawn in.
    Livewire::test(ListInventoryLocations::class)
        ->assertOk()
        ->assertSee('Active')
        ->assertSee('Inactive');
});

it('gives every icon-bearing action an accessible name', function () {
    /** @var array<string, Action> $actions */
    $actions = [
        'correction' => StockMovementResource::correctionAction(),
        'count' => StockLevelResource::countAction(),
        'adjust' => StockLevelResource::adjustAction(),
        'trail' => StockLevelResource::trailAction(),
        'release' => StockReservationResource::releaseAction(),
    ];

    foreach ($actions as $action) {
        // An action carrying an icon is an action Filament may render as an
        // icon button, and an icon button with no label is a control a screen
        // reader announces as "button".
        expect($action->getIcon())->not->toBeNull()
            ->and((string) $action->getLabel())->not->toBe('');
    }
});

it('heads computed columns with words rather than with their attribute names', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 10);

    // Filament humanises a column name into a heading when none is given, which
    // is how `on_hand_after` becomes "On hand after" — right by luck — and
    // `inventory_item_id` becomes "Inventory item id", which is not.
    Livewire::test(ListStockMovements::class)
        ->assertOk()
        ->assertSee('On-hand change')
        ->assertDontSee('On hand delta');

    Livewire::test(ListStockLevels::class)
        ->assertOk()
        ->assertSee('On hand')
        ->assertDontSee('Inventory item id');
});

it('says what an empty cell means rather than leaving it empty', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem(['sku' => null]);
    stocked($item, $location, 10);

    // A movement with no reference and an item with no SKU are both ordinary.
    // A blank cell is indistinguishable from a rendering fault.
    Livewire::test(ListStockMovements::class)
        ->assertOk()
        ->assertSee('No SKU')
        ->assertSee('Nothing named');
});
