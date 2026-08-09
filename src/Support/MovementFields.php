<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Liberu\Ecommerce\InventoryLedger\Enums\MovementReason;

/**
 * The two fields every hand-entered movement carries, and the rule between them.
 *
 * The options are `MovementReason::cases()` rather than a list typed out here.
 * A hardcoded list is a second copy of a closed set that grows by release, and
 * the copy that drifts is always the one in the form — an operator offered a
 * reason the enum no longer has gets a 500, and a reason the enum gained is one
 * nobody can pick.
 *
 * The note requirement is `requiresNote()` for the same reason. `Damaged`,
 * `Lost`, `Stolen`, `Found` and `Correction` are refused by `StockLedger` when
 * the attribution carries no note; asking for it in the form is the difference
 * between a validation message under the field and a red toast after the
 * operator has already lost what they typed.
 */
final class MovementFields
{
    public static function reason(): Select
    {
        return Select::make('reason')
            ->label('Reason')
            ->options(self::options())
            ->required()
            // Live, or the note field cannot become required until something
            // else on the form changes — which for a two-field modal is never.
            ->live();
    }

    public static function note(): Textarea
    {
        return Textarea::make('note')
            ->label('Note')
            ->maxLength(1000)
            ->helperText('Required for damage, loss, theft, a find and a correction — the reasons where the number moved because a person decided it should.')
            ->required(fn (Get $get): bool => self::requiresNote($get('reason')));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (MovementReason::cases() as $reason) {
            $options[$reason->value] = $reason->label();
        }

        return $options;
    }

    /** The reasons a merchant is asked about, as filter values. */
    public static function shrinkageValues(): array
    {
        return array_values(array_map(
            fn (MovementReason $reason): string => $reason->value,
            array_filter(MovementReason::cases(), fn (MovementReason $reason): bool => $reason->isShrinkage()),
        ));
    }

    private static function requiresNote(mixed $reason): bool
    {
        if (! is_string($reason)) {
            return false;
        }

        return MovementReason::tryFrom($reason)?->requiresNote() === true;
    }
}
