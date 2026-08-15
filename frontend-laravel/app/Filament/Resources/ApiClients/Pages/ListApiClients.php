<?php

namespace App\Filament\Resources\ApiClients\Pages;

use App\Filament\Resources\ApiClients\ApiClientResource;
use Filament\Resources\Pages\ListRecords;

class ListApiClients extends ListRecords
{
    protected static string $resource = ApiClientResource::class;
}
