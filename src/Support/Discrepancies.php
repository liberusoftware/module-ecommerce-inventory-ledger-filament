<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Support;

use Liberu\Ecommerce\InventoryLedger\Services\LedgerIntegrity;

/**
 * The runbook's first command, narrowed to one merchant.
 *
 * `LedgerIntegrity::discrepancies()` answers for the estate or for exactly one
 * location, and a merchant has neither — they have a handful. So this asks once
 * and keeps the rows whose location is theirs, rather than asking once per
 * location and turning a dashboard into a query storm.
 *
 * ponytail: one estate-wide scan filtered in PHP. The scan is the same one the
 * runbook opens with, and the result set is expected to be empty; an estate
 * where it is not empty enough to matter has a bigger problem than this widget.
 */
final class Discrepancies
{
    /**
     * Rows keyed by the id of the balance that disagrees with its own history.
     *
     * @param  array<int, mixed>  $locationIds
     * @return array<int, object>
     */
    public static function forLocations(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        $mine = array_map(intval(...), $locationIds);
        $rows = [];

        foreach (app(LedgerIntegrity::class)->discrepancies() as $row) {
            if (in_array((int) $row->location_id, $mine, true)) {
                $rows[(int) $row->level_id] = $row;
            }
        }

        return $rows;
    }
}
