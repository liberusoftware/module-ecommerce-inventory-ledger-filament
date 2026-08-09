<?php

use Liberu\Ecommerce\InventoryLedger\Actions\AdjustStock;
use Liberu\Ecommerce\InventoryLedger\Actions\IssueStock;
use Liberu\Ecommerce\InventoryLedger\Enums\MovementReason;
use Liberu\Ecommerce\InventoryLedger\Enums\MovementType;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource\Pages\ListStockMovements;
use Liberu\Ecommerce\InventoryLedger\Models\StockMovement;
use Liberu\Ecommerce\InventoryLedger\Values\Attribution;
use Livewire\Livewire;

it('lists movements at the team\'s own locations and no others', function () {
    $this->actorForTeam(7);

    $item = anItem();
    stocked($item, aLocation(7), 5);
    stocked($item, aLocation(9), 5);
    // Unowned, and so nobody's. The policy denies every action on an orphan
    // location, and a row visible in a list where every action refuses reads as
    // a broken panel rather than as a denied one.
    stocked($item, aLocation(0, ['team_id' => null]), 5);

    $mine = StockMovement::query()->orderBy('id')->firstOrFail();
    $theirs = StockMovement::query()->orderByDesc('id')->get()->take(2);

    Livewire::test(ListStockMovements::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords($theirs->all());
});

it('shows the balance each row recorded, which is what an investigation reads', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();

    stocked($item, $location, 10);
    held($item, $location, 4);

    // The reservation left on-hand alone and took four out of available, and
    // both numbers are on the row rather than recomputed by the reader.
    Livewire::test(ListStockMovements::class)
        ->assertOk()
        ->assertSee('On hand after')
        ->assertSee('Available after')
        ->assertSee('6');

    $reservation = StockMovement::query()->where('type', MovementType::Reservation)->firstOrFail();

    expect($reservation->on_hand_after)->toBe(10)
        ->and($reservation->available_after)->toBe(6);
});

it('filters the trail down to one item', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $wanted = anItem(['sku' => 'WANTED-1']);
    $other = anItem(['sku' => 'OTHER-1']);

    stocked($wanted, $location, 5);
    stocked($other, $location, 5);

    Livewire::test(ListStockMovements::class)
        ->filterTable('inventory_item_id', $wanted->getKey())
        ->assertCanSeeTableRecords([StockMovement::query()->where('inventory_item_id', $wanted->getKey())->firstOrFail()])
        ->assertCanNotSeeTableRecords([StockMovement::query()->where('inventory_item_id', $other->getKey())->firstOrFail()]);
});

it('filters the trail down to one location', function () {
    $this->actorForTeam(7);

    $here = aLocation(7);
    $there = aLocation(7);
    $item = anItem();

    stocked($item, $here, 5);
    stocked($item, $there, 5);

    Livewire::test(ListStockMovements::class)
        ->filterTable('location_id', $here->getKey())
        ->assertCanSeeTableRecords([StockMovement::query()->where('location_id', $here->getKey())->firstOrFail()])
        ->assertCanNotSeeTableRecords([StockMovement::query()->where('location_id', $there->getKey())->firstOrFail()]);
});

it('filters by reason and by type', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();

    stocked($item, $location, 10);
    app(IssueStock::class)->handle($item, $location, 2);

    $receipt = StockMovement::query()->where('type', MovementType::Receipt)->firstOrFail();
    $issue = StockMovement::query()->where('type', MovementType::Issue)->firstOrFail();

    Livewire::test(ListStockMovements::class)
        ->filterTable('reason', MovementReason::Sale->value)
        ->assertCanSeeTableRecords([$issue])
        ->assertCanNotSeeTableRecords([$receipt]);

    Livewire::test(ListStockMovements::class)
        ->filterTable('type', MovementType::Receipt->value)
        ->assertCanSeeTableRecords([$receipt])
        ->assertCanNotSeeTableRecords([$issue]);
});

it('filters to shrinkage, which is the number a merchant is asked about', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();

    stocked($item, $location, 10);
    app(AdjustStock::class)->handle($item, $location, -1, MovementReason::Damaged, Attribution::system('Crushed in transit.'));
    app(AdjustStock::class)->handle($item, $location, -1, MovementReason::Correction, Attribution::system('Miscounted.'));

    $damaged = StockMovement::query()->where('reason', MovementReason::Damaged)->firstOrFail();
    $corrected = StockMovement::query()->where('reason', MovementReason::Correction)->firstOrFail();

    // The values come from `isShrinkage()`, not from a list typed into the
    // filter — a correction is not shrinkage and must not be counted as it.
    Livewire::test(ListStockMovements::class)
        ->filterTable('shrinkage')
        ->assertCanSeeTableRecords([$damaged])
        ->assertCanNotSeeTableRecords([$corrected]);
});

it('filters by the dates the movements were recorded on', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();

    $this->travelTo(now()->subDays(10));
    stocked($item, $location, 5);
    $old = StockMovement::query()->firstOrFail();

    $this->travelBack();
    app(IssueStock::class)->handle($item, $location, 1);
    $recent = StockMovement::query()->orderByDesc('id')->firstOrFail();

    Livewire::test(ListStockMovements::class)
        ->filterTable('recorded', ['from' => now()->subDays(2)->toDateString()])
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$old]);

    Livewire::test(ListStockMovements::class)
        ->filterTable('recorded', ['until' => now()->subDays(5)->toDateString()])
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$recent]);
});

it('reads oldest first, by id and not by timestamp', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();

    // Written inside the same second, so a sort by `created_at` would order
    // them by whatever the database felt like — and the order they happened in
    // is the entire reason somebody is reading them.
    stocked($item, $location, 5);
    app(IssueStock::class)->handle($item, $location, 1);

    $rows = StockMovement::query()->orderBy('id')->get();

    Livewire::test(ListStockMovements::class)
        ->assertOk()
        ->assertCanSeeTableRecords($rows->all(), inOrder: true);
});
