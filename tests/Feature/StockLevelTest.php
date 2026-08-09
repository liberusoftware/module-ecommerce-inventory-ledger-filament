<?php

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\InventoryLedger\Enums\MovementReason;
use Liberu\Ecommerce\InventoryLedger\Enums\MovementType;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockLevelResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockLevelResource\Pages\ListStockLevels;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\MovementFields;
use Liberu\Ecommerce\InventoryLedger\Models\StockMovement;
use Livewire\Livewire;

it('lists balances at the team\'s own locations and no others', function () {
    $this->actorForTeam(7);

    $item = anItem();
    $mine = stocked($item, aLocation(7), 5);
    $theirs = stocked($item, aLocation(9), 5);

    Livewire::test(ListStockLevels::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('offers no way to create, edit or delete a balance', function () {
    $this->actorForTeam(7);

    $level = stocked(anItem(), aLocation(7), 5);

    // A balance holds no truth of its own. A form that incremented `on_hand`
    // here would write a number with no movement behind it — precisely the
    // corruption `LedgerIntegrity` exists to catch.
    expect(array_keys(StockLevelResource::getPages()))->toBe(['index'])
        ->and(StockLevelResource::canCreate())->toBeFalse()
        ->and(StockLevelResource::canEdit($level))->toBeFalse()
        ->and(StockLevelResource::canDelete($level))->toBeFalse()
        ->and(StockLevelResource::canDeleteAny())->toBeFalse();
});

it('shows available as what is on the shelf less what is spoken for', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();

    stocked($item, $location, 10);
    held($item, $location, 4);

    Livewire::test(ListStockLevels::class)
        ->assertOk()
        ->assertSee('Available')
        // Said in words as well as in a number, because "6" alone does not
        // explain why it is not 10.
        ->assertSee('4 held for somebody');

    expect(balanceOf($item, $location)->available())->toBe(6);
});

it('records a count, and writes the variance as evidence', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    $level = stocked($item, $location, 37);

    // The runbook's answer to "we have 40 on the shelf and the system says 37".
    Livewire::test(ListStockLevels::class)
        ->callAction(TestAction::make('recordCount')->table($level), [
            'counted' => 40,
            'note' => 'Counted twice by two people.',
        ]);

    $count = StockMovement::query()->where('type', MovementType::Count)->firstOrFail();

    expect($count->on_hand_delta)->toBe(3)
        ->and($count->reason)->toBe(MovementReason::Stocktake)
        ->and($count->on_hand_after)->toBe(40)
        ->and($level->refresh()->on_hand)->toBe(40);
});

it('writes a row for a count that agrees, which is the row that says it was checked', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    $level = stocked($item, $location, 40);

    Livewire::test(ListStockLevels::class)
        ->callAction(TestAction::make('recordCount')->table($level), ['counted' => 40, 'note' => null]);

    expect(StockMovement::query()->where('type', MovementType::Count)->count())->toBe(1)
        ->and(StockMovement::query()->where('type', MovementType::Count)->firstOrFail()->on_hand_delta)->toBe(0);
});

it('adjusts a balance with a reason from the enum', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    $level = stocked($item, $location, 10);

    Livewire::test(ListStockLevels::class)
        ->callAction(TestAction::make('adjustStock')->table($level), [
            'delta' => -2,
            'reason' => MovementReason::Damaged->value,
            'note' => 'Water damage on the bottom shelf.',
        ]);

    $adjustment = StockMovement::query()->where('type', MovementType::Adjustment)->firstOrFail();

    expect($adjustment->reason)->toBe(MovementReason::Damaged)
        ->and($adjustment->on_hand_delta)->toBe(-2)
        ->and($level->refresh()->on_hand)->toBe(8);
});

it('refuses a reason that needs a note when no note was given', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    $level = stocked($item, $location, 10);

    Livewire::test(ListStockLevels::class)
        ->callAction(TestAction::make('adjustStock')->table($level), [
            'delta' => -2,
            'reason' => MovementReason::Stolen->value,
            'note' => '',
        ])
        ->assertHasActionErrors(['note']);

    expect($level->refresh()->on_hand)->toBe(10);
});

it('lets a reason that needs no note through without one', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    $level = stocked($item, $location, 10);

    Livewire::test(ListStockLevels::class)
        ->callAction(TestAction::make('adjustStock')->table($level), [
            'delta' => -2,
            'reason' => MovementReason::SupplierReturn->value,
            'note' => null,
        ])
        ->assertHasNoActionErrors();

    expect($level->refresh()->on_hand)->toBe(8);
});

it('offers exactly the reasons the enum has, and no list of its own', function () {
    // The one assertion in this file that touches no Filament API, because the
    // thing being asserted is that no second copy of a closed set exists. A
    // hardcoded list drifts, and the copy that drifts is always the form's: an
    // operator offered a reason the enum lost gets a 500, and one the enum
    // gained is a reason nobody can pick.
    $expected = [];

    foreach (MovementReason::cases() as $reason) {
        $expected[$reason->value] = $reason->label();
    }

    expect(MovementFields::options())->toBe($expected)
        ->and(MovementFields::shrinkageValues())->toBe(['damaged', 'lost', 'stolen']);
});

it('links a balance to the rows that produced it', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    $level = stocked($item, $location, 5);

    $url = StockLevelResource::trailAction()->record($level)->getUrl();

    // The runbook's first query — `where inventory_item_id = ? and location_id
    // = ?` — as a link rather than as a SQL session.
    expect($url)->toContain((string) $item->getKey())
        ->and($url)->toContain((string) $location->getKey())
        ->and($url)->toContain('inventory_item_id');
});
