<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Support;

use Filament\Notifications\Notification;
use Liberu\Ecommerce\InventoryLedger\Exceptions\InsufficientStock;
use Liberu\Ecommerce\InventoryLedger\Exceptions\InvalidMovement;
use Liberu\Ecommerce\InventoryLedger\Exceptions\InvalidReservationTransition;
use Liberu\Ecommerce\InventoryLedger\Exceptions\LedgerIsAppendOnly;

/**
 * Run a domain action from a panel action, and say what happened either way.
 *
 * Every write in this package goes through one of the module's actions, so
 * every write can raise one of the module's four exceptions. The form already
 * refuses what it can see — a reason that needs a note, a quantity of zero —
 * but the check that matters is taken inside `StockLedger`'s transaction, under
 * the row lock, against a balance another operator may have spent since this
 * modal opened. That race is the reason these are caught rather than prevented:
 * an operator who loses it deserves a notification, not a 500.
 *
 * Four `catch` clauses and not one on `Throwable`. Swallowing everything here
 * would turn a genuine bug in this package into a polite red toast, and nobody
 * would ever find it.
 */
final class LedgerWrite
{
    /**
     * @param  callable(): mixed  $write
     * @return bool whether the write landed
     */
    public static function attempt(callable $write, string $success): bool
    {
        try {
            $write();
        } catch (InvalidMovement|InsufficientStock|InvalidReservationTransition|LedgerIsAppendOnly $exception) {
            Notification::make()
                ->title('Nothing was recorded')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()
            ->title($success)
            ->success()
            ->send();

        return true;
    }
}
