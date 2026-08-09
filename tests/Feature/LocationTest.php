<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource\Pages\CreateInventoryLocation;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource\Pages\EditInventoryLocation;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource\Pages\ListInventoryLocations;
use Liberu\Ecommerce\InventoryLedger\Models\InventoryLocation;
use Livewire\Livewire;

it('lists the team\'s locations, and neither another team\'s nor an orphan', function () {
    $this->actorForTeam(7);

    $mine = aLocation(7, ['name' => 'Bermondsey']);
    $theirs = aLocation(9, ['name' => 'Rival Depot']);
    $orphan = InventoryLocation::factory()->create(['name' => 'Nobody\'s Shed']);

    Livewire::test(ListInventoryLocations::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs, $orphan]);
});

it('creates a location into the team the operator is working in', function () {
    $this->actorForTeam(7);

    Livewire::test(CreateInventoryLocation::class)
        ->fillForm([
            'code' => 'BER-1',
            'name' => 'Bermondsey',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $location = InventoryLocation::query()->firstOrFail();

    // The team came off the actor and not off the form. A location created into
    // another team is one the operator immediately cannot see; a null one is an
    // orphan the policy denies everybody, including whoever just made it.
    expect($location->team_id)->toBe(7)
        ->and($location->code)->toBe('BER-1')
        ->and($location->is_active)->toBeTrue();
});

it('deactivates rather than deletes a location that has held something', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    stocked(anItem(), $location, 5);

    // Deleting cascades away the movements, which is the audit trail for
    // everything that ever happened there. The policy refuses, so the button is
    // not there to be pressed.
    Livewire::test(ListInventoryLocations::class)
        ->assertActionHidden(TestAction::make('delete')->table($location));

    Livewire::test(EditInventoryLocation::class, ['record' => $location->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($location->refresh()->is_active)->toBeFalse();
});

it('deletes a location that never held anything', function () {
    $this->actorForTeam(7);

    $empty = aLocation(7);

    Livewire::test(ListInventoryLocations::class)
        ->assertActionVisible(TestAction::make('delete')->table($empty))
        ->callAction(TestAction::make('delete')->table($empty));

    expect(InventoryLocation::query()->whereKey($empty->getKey())->exists())->toBeFalse();
});

it('will not open another team\'s location', function () {
    $this->actorForTeam(7);

    $theirs = aLocation(9);

    $refusal = null;

    try {
        Livewire::test(EditInventoryLocation::class, ['record' => $theirs->getKey()]);
    } catch (ModelNotFoundException|AuthorizationException $exception) {
        // Either answer is correct. The scope keeps it out of the resource's
        // query, and the policy refuses it if anything ever hands it over
        // another way; which of the two answers first is not this test's
        // business, only that one of them does.
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull();
});

it('does not offer to move a location between merchants', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);

    // `team_id` is not a form field. A save that could rewrite it would take
    // every balance, movement and hold in the location along with it, without
    // anybody authorizing that.
    Livewire::test(EditInventoryLocation::class, ['record' => $location->getKey()])
        ->assertOk()
        ->assertFormFieldExists('code')
        ->assertFormFieldExists('name')
        ->assertFormFieldDoesNotExist('team_id');
});
