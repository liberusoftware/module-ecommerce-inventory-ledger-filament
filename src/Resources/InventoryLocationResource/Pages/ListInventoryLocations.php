<?php

namespace Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\InventoryLedger\Filament\Resources\InventoryLocationResource;

class ListInventoryLocations extends ListRecords
{
    protected static string $resource = InventoryLocationResource::class;

    /**
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
