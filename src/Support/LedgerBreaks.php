<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Support;

use Liberu\Ecommerce\InventoryLedger\Models\StockMovement;

/**
 * Where a balance stopped agreeing with its own history.
 *
 * `LedgerIntegrity::discrepancies()` says *which* balances disagree, which is
 * the alarm. This says *where* — the runbook's next step, which is otherwise a
 * SQL session and a running total kept by hand:
 *
 * > Walk it in order and keep a running total of `on_hand_delta` […] the
 * > running total and `on_hand_after` diverge at row *n*. Something wrote the
 * > balance without writing a movement, between row *n-1* and row *n*.
 *
 * This is arithmetic over rows the ledger already recorded and not a rule about
 * them, which is why it lives in the presentation package rather than in the
 * domain: it decides nothing, it only reads faster than a person can.
 *
 * Both columns are walked. `available_after` is `on_hand - reserved` as it
 * stood, so a ledger whose on-hand tracks perfectly can still have lost a
 * reservation, and checking only the loud column would call that balance
 * healthy.
 */
final class LedgerBreaks
{
    /**
     * The id of the first movement whose recorded balance disagrees with the
     * running total, or null when the whole trail adds up.
     *
     * ponytail: loads one balance's whole trail into memory. Correct for an
     * investigation of a handful of flagged balances, which is the only thing
     * that calls it; a sweep over the estate would want the running sum done in
     * SQL with a window function.
     */
    public static function firstBreak(int $itemId, int $locationId): ?int
    {
        $onHand = 0;
        $reserved = 0;

        $trail = StockMovement::query()
            ->forBalance($itemId, $locationId)
            ->get(['id', 'on_hand_delta', 'reserved_delta', 'on_hand_after', 'available_after']);

        foreach ($trail as $movement) {
            $onHand += $movement->on_hand_delta;
            $reserved += $movement->reserved_delta;

            if ($onHand !== $movement->on_hand_after || $onHand - $reserved !== $movement->available_after) {
                return (int) $movement->id;
            }
        }

        return null;
    }
}
