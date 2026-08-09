<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockLevelResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockLevelResource\Pages\ListStockLevels;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockMovementResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\StockReservationResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Widgets\LedgerDiscrepancies;
use Liberu\Ecommerce\InventoryLedger\Filament\Widgets\StockOverview;
use Liberu\Ecommerce\InventoryLedger\Models\InventoryLocation;
use Liberu\Ecommerce\InventoryLedger\Models\StockMovement;
use Liberu\PackageTestbench\TestUser;
use Livewire\Livewire;

it('shows nothing at all to an actor working in no team', function () {
    $this->actingAs(TestUser::factory()->create());

    $item = anItem();
    $location = aLocation(7);
    stocked($item, $location, 5);
    held($item, $location, 1);

    // The resource queries, not the pages: `canViewAny` already refuses the
    // pages, and the scope has to refuse independently for the widgets and bare
    // queries that check never reaches.
    expect(StockMovementResource::getEloquentQuery()->count())->toBe(0)
        ->and(StockLevelResource::getEloquentQuery()->count())->toBe(0)
        ->and(StockReservationResource::getEloquentQuery()->count())->toBe(0)
        ->and(InventoryLocationResource::getEloquentQuery()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBeGreaterThan(0);
});

it('refuses every page to an actor working in no team', function () {
    $this->actingAs(TestUser::factory()->create());

    expect(StockMovementResource::canViewAny())->toBeFalse()
        ->and(StockLevelResource::canViewAny())->toBeFalse()
        ->and(StockReservationResource::canViewAny())->toBeFalse()
        ->and(StockOverview::canView())->toBeFalse()
        ->and(LedgerDiscrepancies::canView())->toBeFalse();
});

it('does not hand an actor with no team every orphan row in the table', function () {
    $this->actingAs(TestUser::factory()->create());

    $item = anItem();
    // Unowned. `where('team_id', null)` compiles to `is null`, so a tenancy
    // scope written the obvious way lists exactly these — the rows the policy
    // denies everybody, handed to the one actor with no claim to anything.
    $orphan = InventoryLocation::factory()->create();
    stocked($item, $orphan, 5);

    expect(InventoryLocationResource::getEloquentQuery()->count())->toBe(0)
        ->and(StockMovementResource::getEloquentQuery()->count())->toBe(0)
        ->and(StockLevelResource::getEloquentQuery()->count())->toBe(0);
});

it('does not hand a real team the orphan rows either', function () {
    $this->actorForTeam(7);

    $item = anItem();
    $orphan = InventoryLocation::factory()->create();
    stocked($item, $orphan, 5);

    expect(InventoryLocationResource::getEloquentQuery()->count())->toBe(0)
        ->and(StockMovementResource::getEloquentQuery()->count())->toBe(0);
});

it('will not write against another team\'s stock even when the id is supplied', function () {
    $this->actorForTeam(7);

    $item = anItem();
    $theirs = aLocation(9);
    $theirLevel = stocked($item, $theirs, 10);

    $refusal = null;

    try {
        // The record is not in the resource's query, so the table has no such
        // row to act on. Tampering with the id in the request finds nothing
        // rather than finding somebody else's shelf.
        Livewire::test(ListStockLevels::class)
            ->assertCanNotSeeTableRecords([$theirLevel])
            ->callAction(TestAction::make('adjustStock')->table($theirLevel), [
                'delta' => -10,
                'reason' => 'correction',
                'note' => 'Helping myself.',
            ]);
    } catch (Throwable $exception) {
        // Whether the record fails to resolve or the action refuses it is not
        // this test's business. That nothing was written is.
        $refusal = $exception;
    }

    expect($theirLevel->refresh()->on_hand)->toBe(10)
        ->and(StockMovement::query()->where('location_id', $theirs->getKey())->count())->toBe(1);
});

it('refuses to move stock at a location that has been deactivated', function () {
    $this->actorForTeam(7);

    $item = anItem();
    $location = aLocation(7);
    $level = stocked($item, $location, 10);

    $location->update(['is_active' => false]);

    // `moveStock` refuses an inactive location; `holdStock` does not. Both
    // buttons are governed by the policy rather than by a rule restated here.
    Livewire::test(ListStockLevels::class)
        ->assertActionHidden(TestAction::make('adjustStock')->table($level))
        ->assertActionHidden(TestAction::make('recordCount')->table($level));
});

it('keeps one merchant\'s discrepancies out of another\'s dashboard', function () {
    $this->actorForTeam(7);

    $item = anItem();
    $theirs = aLocation(9);
    $theirLevel = stocked($item, $theirs, 10);

    // Written behind the ledger's back, which is the only way to make one — and
    // the only place in this suite that reaches past the domain's own API.
    DB::table('inventory_levels')->where('id', $theirLevel->getKey())->update(['on_hand' => 99]);

    Livewire::test(StockOverview::class)
        ->assertOk()
        ->assertSee('Balances agree');
});
