<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Liberu\Ecommerce\InventoryLedger\Values\Attribution;

/**
 * Who is working in the panel, and which team they are working in.
 *
 * The team is the same `current_team_id` `InventoryLocationPolicy` reads,
 * deliberately: a list scoped by one rule and authorized by another shows rows
 * every row action then refuses, which reads as a broken panel rather than as a
 * denied one.
 *
 * Read off the actor rather than off `Filament::getTenant()` because this
 * package does not require the panel to be tenant-aware — an application may
 * attach the plugin to a panel with no tenancy at all, and a null tenant there
 * would silently widen the scope to every merchant.
 *
 * Not the user model: no package may name the application's. `getAttribute()`
 * on `Model` is as far as this goes, and a guard that is not one answers null.
 */
final class PanelActor
{
    public static function id(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }

    public static function teamId(): ?int
    {
        $actor = Auth::user();

        $teamId = $actor instanceof Model ? $actor->getAttribute('current_team_id') : null;

        return $teamId === null ? null : (int) $teamId;
    }

    /**
     * The actor, as the domain wants to record them on a movement.
     *
     * A panel always has somebody signed in, so the system attribution is the
     * unreachable branch rather than the normal one — but `Attribution::by()`
     * takes a non-nullable id, and inventing a zero to satisfy it would write a
     * movement attributed to a user that does not exist.
     */
    public static function attribution(?string $note = null): Attribution
    {
        $id = self::id();

        return $id === null ? Attribution::system($note) : Attribution::by($id, $note);
    }

    /**
     * Scope a query to the locations this team owns.
     *
     * Every level, movement and reservation is reached through a location and
     * the location is where tenancy lives, so one scope answers for the whole
     * package. The relation name is the same on all three.
     *
     * The null team is guarded explicitly. `where('team_id', null)` compiles to
     * `is null`, which would list precisely the unowned rows the policy denies
     * every action on — the loudest possible way for a tenancy scope to fail
     * open.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scopeThroughLocation(Builder $query): Builder
    {
        $teamId = self::teamId();

        return $query->when(
            $teamId === null,
            fn (Builder $scoped) => $scoped->whereRaw('1 = 0'),
            fn (Builder $scoped) => $scoped->whereHas(
                'location',
                fn (Builder $location) => $location->where('team_id', $teamId),
            ),
        );
    }
}
