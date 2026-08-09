<?php

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\InventoryLedger\Actions\ReserveStock;
use Liberu\Ecommerce\InventoryLedger\Enums\MovementType;
use Liberu\Ecommerce\InventoryLedger\Enums\ReservationStatus;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockReservationResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockReservationResource\Pages\ListStockReservations;
use Liberu\Ecommerce\InventoryLedger\Models\StockMovement;
use Livewire\Livewire;

it('lists holds at the team\'s own locations and no others', function () {
    $this->actorForTeam(7);

    $item = anItem();

    $mine = aLocation(7);
    $theirs = aLocation(9);
    stocked($item, $mine, 10);
    stocked($item, $theirs, 10);

    // Both holds before the component mounts: a record created inside the
    // assertion chain did not exist when the table ran its query.
    $ours = held($item, $mine, 2);
    $notOurs = held($item, $theirs, 2);

    Livewire::test(ListStockReservations::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$notOurs]);
});

it('offers no way to create, edit or delete a hold', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 10);
    $hold = held($item, $location, 2);

    // Stock is held by whatever asked for it. A hold typed into a panel has no
    // basket behind it that anything will ever give back.
    expect(array_keys(StockReservationResource::getPages()))->toBe(['index'])
        ->and(StockReservationResource::canCreate())->toBeFalse()
        ->and(StockReservationResource::canEdit($hold))->toBeFalse()
        ->and(StockReservationResource::canDelete($hold))->toBeFalse()
        // The bulk half as well. A bulk write to a ledger is a hundred rows
        // nobody reviewed, attributed to one click.
        ->and(StockReservationResource::canDeleteAny())->toBeFalse();
});

it('releases a hold through the domain action', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 10);
    $hold = held($item, $location, 4);

    expect(balanceOf($item, $location)->available())->toBe(6);

    Livewire::test(ListStockReservations::class)
        ->callAction(TestAction::make('releaseHold')->table($hold));

    $release = StockMovement::query()->where('type', MovementType::Release)->firstOrFail();

    expect($hold->refresh()->status)->toBe(ReservationStatus::Released)
        ->and($release->reserved_delta)->toBe(-4)
        // On-hand untouched: nothing left the shelf, it simply stopped being
        // spoken for.
        ->and($release->on_hand_delta)->toBe(0)
        ->and(balanceOf($item, $location)->available())->toBe(10);
});

it('hides the release on a hold that has already ended', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 10);

    $open = held($item, $location, 2);
    $done = held($item, $location, 2);

    Livewire::test(ListStockReservations::class)
        ->callAction(TestAction::make('releaseHold')->table($done));

    // A button whose only outcomes are "nothing happened" and "an error" is
    // worse than no button.
    Livewire::test(ListStockReservations::class)
        ->assertActionVisible(TestAction::make('releaseHold')->table($open))
        ->assertActionHidden(TestAction::make('releaseHold')->table($done->refresh()));
});

it('still offers the release at an inactive location', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 10);
    $hold = held($item, $location, 2);

    $location->update(['is_active' => false]);

    // `holdStock`, not `moveStock`. Deactivating a location must not strand
    // every basket pointing at it.
    Livewire::test(ListStockReservations::class)
        ->assertActionVisible(TestAction::make('releaseHold')->table($hold));
});

it('finds the overdue holds and leaves the open-ended ones alone', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 20);

    $this->travelTo(now()->subDays(2));
    $overdue = held($item, $location, 2);
    $this->travelBack();

    $fresh = held($item, $location, 2);
    // Legitimate: an allocation against a paid order is held until released.
    $forever = app(ReserveStock::class)->handle($item, $location, 2, null, null, indefinite: true);

    Livewire::test(ListStockReservations::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$fresh, $forever]);
});

it('says a hold with no expiry is held until released, rather than leaving the cell blank', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 10);
    app(ReserveStock::class)->handle($item, $location, 2, null, null, indefinite: true);

    Livewire::test(ListStockReservations::class)
        ->assertOk()
        ->assertSee('Held until released');
});
