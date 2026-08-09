# Presentation — what this package puts in a panel

Everything here is contributed by `InventoryLedgerPlugin`, which the application
attaches to whichever panel it wants. Nothing registers itself.

## The rule that shapes all of it

**The ledger is append-only, and this package does not pretend otherwise.**

`StockMovement` refuses `update` and `delete` at the model. A Filament resource
scaffolded from habit offers an edit action and a delete action, and both then
fail with `LedgerIsAppendOnly` — which is a worse experience than not offering
them, because by the time the refusal arrives the operator has already decided
what they wanted to change.

So on the movement resource there is:

- no `edit` page and no `EditAction`;
- no `DeleteAction` and no `DeleteBulkAction`;
- `canCreate()`, `canEdit()`, `canDelete()` and `canDeleteAny()` all returning
  false explicitly.

What replaces them is **Record a correction**, which appends a new movement with
reason `Correction` and a note. That is the operation the operator actually
wants, it is auditable, and it is reversible by the same means.

Balances are read-only for the same reason one step down. A form that
incremented `on_hand` would write a number with no movement behind it — exactly
the corruption `LedgerIntegrity::discrepancies()` exists to catch. Every write in
this package goes through a domain action.

## Surfaces

| Surface | Navigation | Covers |
| --- | --- | --- |
| `Resources\StockMovementResource` (list) | Inventory › Movements | The trail. Filterable by item, location, type, reason, shrinkage and date; every row carries the `on_hand_after` and `available_after` it recorded |
| `Resources\StockLevelResource` (list) | Inventory › Balances | On hand, reserved and available per item and location |
| `Resources\StockReservationResource` (list) | Inventory › Holds | Stock spoken for, and the overdue holds a stalled sweep leaves behind |
| `Resources\InventoryLocationResource` (list, create, edit) | Inventory › Locations | Where stock is held — and the only thing here a panel may create |
| `Widgets\StockOverview` | Dashboard | Locations, on hand, reserved, and whether the balances can be believed |
| `Widgets\LedgerDiscrepancies` | Dashboard | Balances that disagree with their own history, both numbers side by side, and where the trail first diverges |

### Why the trail is a resource and not a sub-tab

An operator comes here to answer one question — *why does this say 3 when the
shelf has 5* — and the only thing that answers it is the rows, in order, with
the balance each one recorded at the time. That is a table somebody filters,
sorts and reads for several minutes, not a panel hanging off the side of a
balance.

The **Movement trail** action on a balance is the link between the two: it opens
the movement resource already filtered to that one item at that one location,
which is the runbook's opening `select` as a link rather than as a SQL session.

## Every write, and the action behind it

| Where | Action | Domain action | Ability asked |
| --- | --- | --- | --- |
| Movements → Record a correction | `recordCorrection` | `AdjustStock` with `MovementReason::Correction` | `moveStock` on the location |
| Balances → Record a count | `recordCount` | `RecordCount` | `moveStock` on the location |
| Balances → Adjust | `adjustStock` | `AdjustStock` | `moveStock` on the location |
| Holds → Release | `releaseHold` | `ReleaseReservation` | `holdStock` on the location |
| Locations → Create / Edit / Delete | Filament defaults | — (see below) | `create`, `update`, `delete` |

Because every write goes through an action, the domain events come with them:
`StockMovementRecorded` and `ReservationStatusChanged` are dispatched from the
panel exactly as they are from a queued job or a console command, and a listener
rebuilding an index does not have to know which surface caused the change.

Domain exceptions — `InvalidMovement`, `InsufficientStock`,
`InvalidReservationTransition`, `LedgerIsAppendOnly` — are caught in
`Support\LedgerWrite` and become a notification. Four `catch` clauses and not one
on `Throwable`: swallowing everything would turn a genuine bug in this package
into a polite red toast nobody would ever find.

### Known deviation: creating a location does not go through a domain action

`liberusoftware/ecommerce-inventory-ledger` publishes no `CreateLocation`. Its
actions exist for the things that carry a rule, and every one of them writes the
ledger; a location carries none beyond its unique code, which the database
enforces. So `CreateInventoryLocation::handleRecordCreation()` calls
`InventoryLocation::query()->create()` directly.

`team_id` is still not a form field on either the create or the edit form — it
comes off the actor. It is the tenant key the policy authorizes against, and a
save that could rewrite it would move every balance, movement and hold in the
location to another merchant without anybody authorizing that.

This resolves the day the domain package publishes a location action.

## Reason codes

`MovementReason` is a closed enum, and `Support\MovementFields` reads it rather
than restating it:

- the options are `MovementReason::cases()`, so a release that adds or removes a
  reason changes this surface with it;
- the note is required exactly when `requiresNote()` says so — `Damaged`,
  `Lost`, `Stolen`, `Found` and `Correction`, the reasons where the number moved
  because a person decided it should;
- the shrinkage filter's values are `isShrinkage()`, so a correction is not
  counted as shrinkage and a new shrinkage reason is picked up for free.

A hardcoded list would be a second copy of a set that grows by release, and the
copy that drifts is always the form's: an operator offered a reason the enum lost
gets a 500, and one the enum gained is a reason nobody can pick.

The note is asked for **in the form**. `StockLedger` refuses the movement without
it either way; asking first is the difference between a message under the field
and a red toast after the operator has lost what they typed.

## Authorization and tenancy

The domain registers exactly one policy, `InventoryLocationPolicy`, because every
balance, movement and hold is reached through a location and the location is
where tenancy lives.

That has a consequence this package has to handle explicitly: **there is no
policy for `StockMovement`, `StockLevel` or `StockReservation` at all**, so every
ability on them is unpublished, and an ability the gate has no opinion about
defaults permissive. The three read-only resources therefore state
`canViewAny()`, `canCreate()`, `canEdit()`, `canDelete()` and `canDeleteAny()`
themselves rather than leaving the unanswered case to decide.

Scoping is `Support\PanelActor::scopeThroughLocation()`, and the null team is
guarded with `whereRaw('1 = 0')`. `where('team_id', null)` compiles to `is null`,
which would list precisely the unowned locations the policy denies everything on
— the loudest possible way for a tenancy scope to fail open.

The team is read from the actor's `current_team_id` — the same column the policy
reads — and not from `Filament::getTenant()`. Nothing in the manifest says the
host's panel is tenant-aware, and a null tenant on a panel that is not would
silently widen the scope to every merchant.

## Integrity, on the dashboard

`LedgerIntegrity::discrepancies()` is the module's whole argument made runnable.
A check nobody runs is an assurance nobody has, so it is answered on the
dashboard before anybody thinks to ask.

`Widgets\LedgerDiscrepancies` shows, per disagreeing balance:

- what the balance says and what the movements add up to, side by side, because
  the difference between them is the size of the problem and one number alone is
  only an accusation;
- **where the trail first diverges** — `Support\LedgerBreaks::firstBreak()` walks
  the rows keeping a running total and returns the first movement whose recorded
  balance stops tracking it. That is the runbook's investigation, done. Both
  columns are walked: a ledger whose on-hand tracks perfectly can still have lost
  a reservation.
- *Nowhere* is a real answer and a different fault. A trail that adds up under a
  balance that does not means nothing corrupted the history — something wrote the
  balance after the last movement.

The check is recomputed on every render rather than cached. A cached integrity
check is one that can be stale, and a stale "everything agrees" is worse than no
widget at all.

## Accessibility

No meaning is carried by colour or by an icon alone.

- A location's state is a badge reading **Active** / **Inactive**, not a tick.
  A tick and a cross are the same to a screen reader, and the same to anybody who
  cannot separate the two colours they are drawn in.
- Movement type and reason, and a hold's status, render their `label()` text.
  Shrinkage is drawn in a warning colour, and the colour repeats the word rather
  than carrying it.
- Every action carrying an icon carries a label, because Filament may render it
  as an icon button and an icon button with no label announces as "button".
- Empty cells say what empty means: *No SKU*, *Nothing named*, *Held until
  released*, *System*. A blank cell is indistinguishable from a rendering fault.
- The integrity stat reads "Balances agree" or "3 disagree" in words as well as
  in green or red.

## Theme, cache and discovery

Nothing here ships a Blade view, a CSS file or a JavaScript asset, so the panel's
own theme applies unchanged and there is nothing to publish or recompile.

The plugin **lists** what it contributes rather than scanning `src/`, so
`php artisan filament:cache-components` is a no-op worth running and boot walks
no filesystem. The cost is that a class added later without being registered is
simply absent from the panel — no error, just a resource nobody can reach — which
is why `tests/Feature/PanelTest.php` asserts the registered set equals the
classes in `src/Resources` and `src/Widgets` exactly.

## Deliberately left out

- **Receiving, issuing and transferring stock.** `ReceiveStock`, `IssueStock`,
  `ReturnStock` and `TransferStock` exist in the domain and are not surfaced
  here. A receipt belongs to purchasing, an issue to fulfilment, and a transfer
  to warehouse operations — epic #922 — each of which knows the delivery note,
  the order or the van that this panel does not. `Adjust` with the right reason
  covers the operator's hand-entry case and leaves a reason code behind, which a
  free-standing *receive* button would not.
- **Committing a hold.** `CommitReservation` is a despatch, and despatching from
  an inventory panel would record a fulfilment with no order behind it.
- **Creating a hold.** Stock is held by whatever asked for it. A hold typed in
  here has no basket behind it that anything will ever give back.
- **Editing an inventory item** — SKU, cost, whether it is tracked. Those belong
  with the catalogue that owns the product, and `ecommerce-catalog` is where a
  merchant expects to find them.
- **Bulk actions of every kind.** A bulk write to a ledger is a hundred rows
  nobody reviewed, attributed to one click.
- **A resource for `InventoryItem`.** It is a stock-keeping identity created
  lazily by the domain the first time anybody stocks a product; there is nothing
  for an operator to do to one that is not really a change to the product.
