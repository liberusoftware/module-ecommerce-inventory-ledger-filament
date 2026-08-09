<?php

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\InventoryLedger\Enums\MovementReason;
use Liberu\Ecommerce\InventoryLedger\Exceptions\LedgerIsAppendOnly;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource\Pages\ListStockMovements;
use Liberu\Ecommerce\InventoryLedger\Models\StockMovement;
use Livewire\Livewire;

// The rule that shapes the whole package: the ledger is append-only, and the UI
// must not pretend otherwise. A resource scaffolded from habit offers an edit
// action and a delete action, both of which fail at the model — which is a worse
// experience than not offering them, because by then the operator has already
// decided what to change.

it('publishes no page for creating, editing or deleting a movement', function () {
    expect(array_keys(StockMovementResource::getPages()))->toBe(['index']);
});

it('has no route to an edit or create page even by name', function () {
    $this->actorForTeam(7);

    $movement = StockMovement::query()->firstOrNew();

    // Not merely absent from the navigation: unregistered, so anything that
    // asks for the URL fails loudly rather than linking somewhere blank.
    expect(fn (): string => StockMovementResource::getUrl('create'))->toThrow(Exception::class)
        ->and(fn (): string => StockMovementResource::getUrl('edit', ['record' => $movement]))->toThrow(Exception::class)
        ->and(StockMovementResource::getUrl('index'))->toBeString();
});

it('answers no to every write ability rather than leaving it unpublished', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 5);

    $movement = StockMovement::query()->firstOrFail();

    // There is no `StockMovement` policy at all — the domain registers one, for
    // `InventoryLocation` — so an ability nobody publishes is an ability the
    // gate has no opinion about, and the fallback has been permissive.
    expect(StockMovementResource::canCreate())->toBeFalse()
        ->and(StockMovementResource::canEdit($movement))->toBeFalse()
        ->and(StockMovementResource::canDelete($movement))->toBeFalse()
        ->and(StockMovementResource::canDeleteAny())->toBeFalse();
});

it('would have failed at the model if it had offered an edit', function () {
    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 5);

    $movement = StockMovement::query()->firstOrFail();

    // This is why the actions are absent rather than merely discouraged.
    expect(fn () => $movement->update(['note' => 'rewritten']))->toThrow(LedgerIsAppendOnly::class)
        ->and(fn () => $movement->delete())->toThrow(LedgerIsAppendOnly::class);
});

it('says on the page that a correction is a new movement', function () {
    $this->actorForTeam(7);

    Livewire::test(ListStockMovements::class)
        ->assertOk()
        // An operator looking at rows with no edit and no delete needs telling
        // that is the design, or they conclude the panel is broken.
        ->assertSee('append-only');
});

it('records a correction as a new movement and leaves the original alone', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 5);

    $original = StockMovement::query()->firstOrFail();

    Livewire::test(ListStockMovements::class)
        ->callAction(TestAction::make('recordCorrection')->table($original), [
            'delta' => -2,
            'note' => 'Two were on the returns trolley and were counted twice.',
        ]);

    $correction = StockMovement::query()->orderByDesc('id')->firstOrFail();

    expect(StockMovement::query()->count())->toBe(2)
        ->and($correction->getKey())->not->toBe($original->getKey())
        ->and($correction->reason)->toBe(MovementReason::Correction)
        ->and($correction->on_hand_delta)->toBe(-2)
        ->and($correction->note)->toBe('Two were on the returns trolley and were counted twice.')
        // The balance moved because the ledger did.
        ->and(balanceOf($item, $location)->on_hand)->toBe(3)
        // And the row it corrects is byte-for-byte what it was.
        ->and($original->refresh()->on_hand_delta)->toBe(5)
        ->and($original->on_hand_after)->toBe(5);
});

it('attributes the correction to whoever recorded it', function () {
    $actor = $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 5);

    Livewire::test(ListStockMovements::class)
        ->callAction(TestAction::make('recordCorrection')->table(StockMovement::query()->firstOrFail()), [
            'delta' => 1,
            'note' => 'Found one behind the racking.',
        ]);

    expect((int) StockMovement::query()->orderByDesc('id')->firstOrFail()->actor_id)->toBe((int) $actor->getKey());
});

it('refuses a correction with no note, in the form rather than after the fact', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 5);

    // `MovementReason::Correction` is refused by `StockLedger` without a note.
    // Catching it here is the difference between a message under the field and
    // a red toast after the operator has lost what they typed.
    Livewire::test(ListStockMovements::class)
        ->callAction(TestAction::make('recordCorrection')->table(StockMovement::query()->firstOrFail()), [
            'delta' => -2,
            'note' => '',
        ])
        ->assertHasActionErrors(['note']);

    expect(StockMovement::query()->count())->toBe(1);
});

it('refuses a correction of nothing without failing the request', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 5);

    // Zero is not a correction, and `AdjustStock` says so. Reaching the
    // assertions at all is what proves the domain exception became a
    // notification rather than a 500.
    Livewire::test(ListStockMovements::class)
        ->callAction(TestAction::make('recordCorrection')->table(StockMovement::query()->firstOrFail()), [
            'delta' => 0,
            'note' => 'Nothing changed.',
        ]);

    expect(StockMovement::query()->count())->toBe(1)
        ->and(balanceOf($item, $location)->on_hand)->toBe(5);
});

it('hides the correction at a location nobody may move stock in', function () {
    $this->actorForTeam(7);

    $open = aLocation(7);
    $closed = aLocation(7, ['is_active' => false]);
    $item = anItem();

    stocked($item, $open, 5);
    stocked($item, $closed, 5);

    $atOpen = StockMovement::query()->where('location_id', $open->getKey())->firstOrFail();
    $atClosed = StockMovement::query()->where('location_id', $closed->getKey())->firstOrFail();

    // `moveStock` refuses an inactive location, and the button that would fail
    // is not offered.
    Livewire::test(ListStockMovements::class)
        ->assertActionVisible(TestAction::make('recordCorrection')->table($atOpen))
        ->assertActionHidden(TestAction::make('recordCorrection')->table($atClosed));
});
