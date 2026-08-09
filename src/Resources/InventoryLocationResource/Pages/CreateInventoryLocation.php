<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource;
use Liberu\Ecommerce\InventoryLedger\Filament\Support\PanelActor;
use Liberu\Ecommerce\InventoryLedger\Models\InventoryLocation;

class CreateInventoryLocation extends CreateRecord
{
    protected static string $resource = InventoryLocationResource::class;

    /**
     * The team comes off the actor, never off the form.
     *
     * It is the tenant key `InventoryLocationPolicy` authorizes against, so a
     * location created into another team is one the operator immediately cannot
     * see; and a null one is an orphan the policy denies everybody — including
     * the person who just made it.
     *
     * There is no `CreateLocation` domain action to delegate to. The domain
     * publishes actions for the things that carry a rule — every one of them
     * writes the ledger — and a location carries none beyond its unique code,
     * which the database enforces. Recorded in docs/presentation.md as the one
     * write in this package that does not go through an action.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return InventoryLocation::query()->create([
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'team_id' => PanelActor::teamId(),
        ]);
    }
}
