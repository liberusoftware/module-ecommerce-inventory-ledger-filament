# Runbook — Inventory Ledger administration

What breaks in the panel, what it looks like, and what to do. Failures in the
ledger itself — a discrepancy, a stalled sweep, negative on-hand — are in the
domain package's own [runbook](https://github.com/liberusoftware/module-ecommerce-inventory-ledger/blob/main/docs/runbook.md).
This file is about the screens.

## Nothing appears in the panel at all

The plugin is not attached. This package registers itself into no panel; the
application composes its own and adds `InventoryLedgerPlugin::make()` to the
`plugins()` array. See [adoption.md](adoption.md#attaching-the-plugin-to-a-panel).

If it is attached and there is still nothing, the module is not enabled:

```dotenv
MODULES_ENABLED=ecommerce-inventory-ledger,ecommerce-inventory-ledger-filament
```

Both. With the domain module disabled there are no tables and no policy, and the
resources have nothing to read.

If components were cached after a change, `php artisan filament:optimize-clear`.

## An operator sees the navigation but every screen is empty

Almost always **no `current_team_id` on the actor**. Everything here is scoped by
it, and an actor without one is refused every page and shown zero rows.

```php
auth()->user()->current_team_id;   // null is the fault
```

This is deliberate and is not fixed by loosening the scope. `where('team_id',
null)` compiles to `is null`, so a scope written the obvious way would hand that
actor every *unowned* location on the estate — precisely the rows the policy
denies everybody.

If the actor does have a team, check the locations:

```sql
select id, code, name, team_id, is_active from ecommerce_inventory_ledger_locations;
```

`team_id` null is an orphan and is denied to everybody. Assign it rather than
loosening anything.

## The Adjust and Record-a-count buttons are missing

`InventoryLocationPolicy::moveStock()` refuses an **inactive** location. That is
what deactivating a location means: it keeps its stock and its history and
accepts no new movements.

Release is still offered there, because it asks `holdStock` — deactivating a
location must not strand every basket pointing at it.

To receive into it again, reactivate it: Inventory › Locations › Edit › Active.

## There is no Edit or Delete on a movement

**Working as intended.** The ledger is append-only and `StockMovement` refuses
both at the model. Offering buttons that fail would be worse than not offering
them.

The correction the operator wants is **Record a correction** on the row itself:
it appends a new movement with reason `Correction` and a note. If somebody is
asking for an edit, what they want is either that or a **Record a count**.

If a data-repair script is hitting `LedgerIsAppendOnly`, the script is the
problem — see the domain runbook.

## "Nothing was recorded" after submitting a correction or an adjustment

A domain rule refused it, and the notification carries the reason. The three that
reach an operator:

| Message | What happened |
| --- | --- |
| quantity … | The delta was zero. Zero is not an adjustment; record a count instead |
| must carry a note | `Damaged`, `Lost`, `Stolen`, `Found` or `Correction` with no note. Do not record it as `Sale` to get around this — that is how shrinkage becomes invisible |
| insufficient stock | The adjustment would take on-hand or available below zero. Something spent the balance between the modal opening and it being submitted |

The last one is a lost race and not a bug: the availability check is taken inside
`StockLedger`'s transaction, under a row lock, which is the only place it means
anything. Re-open the row and look at the current numbers.

## The integrity widget says balances disagree

That is the incident, and it is the domain runbook's first section. From here:

1. The widget names the balance, both numbers, and **where the trail first
   diverges**.
2. *Movement #N* means something wrote the balance without writing a movement
   between row N-1 and row N. Use **Movement trail** on that row and read from
   there. `StockLedger` is the only thing in the domain package that touches
   `inventory_levels`, so the writer is host code, an import or a seeder.
3. *Nowhere — the trail adds up, the balance does not* means the history is
   intact and the balance row was written after the last movement. Same class of
   writer, different moment.

Do **not** fix it by adjusting the balance to match. Record a count of what is
actually on the shelf; that writes the variance as evidence and leaves the ledger
able to explain itself.

## The Holds screen is full of overdue rows

The expiry sweep is not running. It is the application's scheduler, not this
package's:

```php
Schedule::call(fn () => app(ReleaseExpiredReservations::class)->handle())->everyFiveMinutes();
```

Releasing them by hand from this screen works and does not fix the cause. A hold
with **Held until released** and no expiry is a different thing and legitimate —
an allocation against a paid order — and the *Overdue* filter excludes those
deliberately.

## The dashboard is slow

`Widgets\LedgerDiscrepancies` recomputes the integrity check on every render, and
that check joins the movements table to the balances across the estate. It is
deliberately not cached: a cached integrity check is one that can be stale, and a
stale "everything agrees" is worse than no widget at all.

On an estate large enough for that to hurt, drop the widget from the panel's
dashboard and run the check on a schedule instead — the domain package's
`LedgerIntegrity::discrepancies()` is the same call. The stats widget is cheap
and can stay.

## A location cannot be deleted

`InventoryLocationPolicy::delete()` permits it only for a location that never
held anything, so Filament hides the button. Deleting cascades away the
movements, which is the audit trail for everything that ever happened there.

Deactivate it instead. That stops new stock arriving and keeps the history.

## After upgrading the domain package

This package pins `^0.1`. If the panel starts throwing on a class or method that
is not there, the tree resolved a domain version this release was not built
against — check `composer show liberusoftware/ecommerce-inventory-ledger` against
the constraint in `composer.json`.
