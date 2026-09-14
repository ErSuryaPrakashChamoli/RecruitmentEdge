<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Filament\Resources\Offers\OfferResource;
use App\Services\OfferService;
use App\Services\SequenceCodeGenerator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOffer extends CreateRecord
{
    protected static string $resource = OfferResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['offer_code'] = app(SequenceCodeGenerator::class)->next('OFR');
        $data['created_by'] = Filament::auth()->user()?->employee_id;

        return $data;
    }

    /**
     * Routed through OfferService so the offer's initial status history row is written with it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(OfferService::class)->create($data, Filament::auth()->user()?->employee);
    }
}
