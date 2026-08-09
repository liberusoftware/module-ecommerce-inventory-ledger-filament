<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource;

class EditInventoryLocation extends EditRecord
{
    protected static string $resource = InventoryLocationResource::class;

    /**
     * `team_id` is not in the form, so the save cannot move a location between
     * merchants — which would take every balance, movement and hold in it along
     * without anybody authorizing that.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
