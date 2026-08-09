# Changelog

## 0.1.0 - 2026-08-09

- First release. A Filament 5 presentation adapter for
  `liberusoftware/ecommerce-inventory-ledger`, contributing four resources and two widgets to a
  panel the application composes.
- **The movement trail is the centre of the package**, not a sub-tab: its own resource, filterable
  by item, location, type, reason, shrinkage and date, with the `on_hand_after` and
  `available_after` each row recorded on the row, and read oldest-first by id rather than by
  timestamp.
- **The append-only rule is visible rather than merely documented.** The movement resource
  publishes no create, edit or delete page and answers false to `canCreate()`, `canEdit()`,
  `canDelete()` and `canDeleteAny()` — necessary rather than decorative, since the domain registers
  no `StockMovement` policy and an unpublished ability defaults permissive. A correction is a new
  movement with reason `Correction` and a required note, recorded from the row it corrects.
- Balances are read-only for the same reason one step down. Every write goes through a domain
  action — `AdjustStock`, `RecordCount`, `ReleaseReservation` — so nothing in this package can
  increment a column and make `LedgerIntegrity::discrepancies()` non-empty.
- `LedgerIntegrity` is surfaced on the dashboard: a stat that reads "Balances agree" or "3
  disagree", and a table of the disagreeing balances showing what the balance says beside what the
  movements add up to, plus `Support\LedgerBreaks` naming the first movement where the trail stops
  tracking — the runbook's manual walk, computed. Both the on-hand and the available columns are
  walked, so a lost reservation under a perfect on-hand trail is still caught.
- Reason codes come from `MovementReason::cases()` and the note is required exactly when
  `requiresNote()` says so, so this package keeps no second copy of a closed set that grows by
  release. The shrinkage filter reads `isShrinkage()` for the same reason.
- Holds carry an *Overdue* filter reading the domain's own `expired()` scope — the most common
  cause of "insufficient stock on a product the shop clearly has" is a sweep that is not running —
  and a release that asks `holdStock` rather than `moveStock`, so deactivating a location strands
  no baskets.
- Authorization is `InventoryLocationPolicy` throughout, and every resource query is scoped to the
  team the actor is working in — the same `current_team_id` the policy reads — with the null team
  guarded by `whereRaw('1 = 0')` rather than a `where('team_id', null)` that would list every
  orphan row on the estate.
- Accessibility: no meaning carried by colour or icon alone. Location state, movement type and
  reason, and hold status all render their words; every icon-bearing action carries a label; every
  empty cell says what empty means.
- Ships no migration, no config file, no Blade view and no asset. Installing boots nothing —
  `ModuleManagerServiceProvider` registers the module only when the deployment names it in
  `MODULES_ENABLED`.
