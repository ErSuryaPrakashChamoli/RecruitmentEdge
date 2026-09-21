<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Filament\Resources\CandidateApplications\Schemas\ApplicationPicker;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Offers\Schemas\OfferForm;
use App\Models\CandidateApplication;
use App\Services\OfferService;
use App\Services\SequenceCodeGenerator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOffer extends CreateRecord
{
    protected static string $resource = OfferResource::class;

    /**
     * The applications' "Raise Offer" action links here with ?application={id}. Pre-selects that
     * application (and its requisition's designation/location) so nobody has to look up the code.
     * Unknown or out-of-scope ids are ignored; the form's own rule re-checks scope on submit.
     */
    protected function afterFill(): void
    {
        $application = ApplicationPicker::selectableApplications()
            ->with('requisition')
            ->find(request()->integer('application'));

        if (! $application instanceof CandidateApplication) {
            return;
        }

        $this->data = [
            ...$this->data,
            'candidate_application_id' => $application->id,
            ...OfferForm::requisitionDefaults($application),
        ];
    }

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
