<?php

use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\Discrepancies;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\LedgerBreaks;
use Liberu\Ecommerce\InventoryLedger\Filament\Widgets\LedgerDiscrepancies;
use Liberu\Ecommerce\InventoryLedger\Filament\Widgets\StockOverview;
use Liberu\Ecommerce\InventoryLedger\Models\StockMovement;
use Livewire\Livewire;

// A discrepancy nobody sees is a stock count nobody trusts. Every arrangement
// below writes a balance behind the ledger's back through the query builder,
// because nothing in the package's own API can — which is the point.

it('says the balances agree when they do', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 10);
    held($item, $location, 4);

    Livewire::test(StockOverview::class)
        ->assertOk()
        ->assertSee('Balances agree')
        ->assertSee('10');

    Livewire::test(LedgerDiscrepancies::class)
        ->assertOk()
        ->assertSee('Every balance agrees with its ledger');
});

it('counts a balance that stopped agreeing with its own history', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    $level = stocked($item, $location, 10);

    DB::table('inventory_levels')->where('id', $level->getKey())->update(['on_hand' => 13]);

    Livewire::test(StockOverview::class)
        ->assertOk()
        ->assertSee('1 disagree')
        ->assertSee('Something wrote a balance without writing a movement');
});

it('lists the disagreeing balance with both numbers side by side', function () {
    $this->actorForTeam(7);

    $location = aLocation(7, ['name' => 'Bermondsey']);
    $item = anItem(['sku' => 'BREAD-1']);
    $level = stocked($item, $location, 10);

    DB::table('inventory_levels')->where('id', $level->getKey())->update(['on_hand' => 13]);

    // One number alone is only an accusation. The difference between the two is
    // the size of the problem.
    Livewire::test(LedgerDiscrepancies::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$level])
        ->assertSee('BREAD-1')
        ->assertSee('13')
        ->assertSee('10');
});

it('points at the first movement whose recorded balance stopped tracking', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    $level = stocked($item, $location, 10);
    stocked($item, $location, 5);
    stocked($item, $location, 5);

    $trail = StockMovement::query()->forBalance($item->getKey(), $location->getKey())->get();
    $second = $trail[1];

    // The model refuses this, so the query builder is the only way to make a
    // trail that stops adding up — which is exactly the corruption the runbook
    // says to walk the rows looking for. The delta and not the recorded
    // balance, so the balance stops agreeing with the sum as well: the widget
    // lists what disagrees, and a trail that diverges under a balance that
    // still adds up is a fault nothing has noticed yet.
    DB::table('ecommerce_inventory_ledger_movements')
        ->where('id', $second->getKey())
        ->update(['on_hand_delta' => 99]);

    expect(LedgerBreaks::firstBreak((int) $item->getKey(), (int) $location->getKey()))->toBe((int) $second->getKey());

    Livewire::test(LedgerDiscrepancies::class)
        ->assertOk()
        ->assertSee('Movement #'.$second->getKey());
});

it('says so when the trail adds up and only the balance is wrong', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    $level = stocked($item, $location, 10);

    DB::table('inventory_levels')->where('id', $level->getKey())->update(['on_hand' => 13]);

    // A different fault, and reporting it as a blank cell would send the
    // investigation to read rows that are all correct.
    expect(LedgerBreaks::firstBreak((int) $item->getKey(), (int) $location->getKey()))->toBeNull();

    Livewire::test(LedgerDiscrepancies::class)
        ->assertOk()
        ->assertSee('the trail adds up, the balance does not');
});

it('notices a lost reservation even when the on-hand column tracks perfectly', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    stocked($item, $location, 10);
    $hold = held($item, $location, 4);

    $reservation = StockMovement::query()->where('reservation_id', $hold->getKey())->firstOrFail();

    // On-hand is untouched by a hold, so a check that walked only the loud
    // column would call this balance healthy.
    DB::table('ecommerce_inventory_ledger_movements')
        ->where('id', $reservation->getKey())
        ->update(['available_after' => 10]);

    expect(LedgerBreaks::firstBreak((int) $item->getKey(), (int) $location->getKey()))->toBe((int) $reservation->getKey());
});

it('asks the estate once rather than once per location', function () {
    $this->actorForTeam(7);

    $item = anItem();
    $mine = aLocation(7);
    $theirs = aLocation(9);

    $mineLevel = stocked($item, $mine, 10);
    $theirLevel = stocked($item, $theirs, 10);

    DB::table('inventory_levels')->whereIn('id', [$mineLevel->getKey(), $theirLevel->getKey()])->update(['on_hand' => 13]);

    $rows = Discrepancies::forLocations([$mine->getKey()]);

    expect(array_keys($rows))->toBe([(int) $mineLevel->getKey()])
        ->and((int) $rows[$mineLevel->getKey()]->on_hand)->toBe(13)
        ->and((int) $rows[$mineLevel->getKey()]->on_hand_from_ledger)->toBe(10)
        ->and(Discrepancies::forLocations([]))->toBe([]);
});

it('links a disagreeing balance straight to the rows behind it', function () {
    $this->actorForTeam(7);

    $location = aLocation(7);
    $item = anItem();
    $level = stocked($item, $location, 10);

    DB::table('inventory_levels')->where('id', $level->getKey())->update(['on_hand' => 13]);

    Livewire::test(LedgerDiscrepancies::class)
        ->assertOk()
        ->assertSee('Movement trail');
});
