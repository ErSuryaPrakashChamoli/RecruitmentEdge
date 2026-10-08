<?php

namespace App\Services\Platform\Commercial;

use App\Models\User;
use DomainException;

/**
 * SaaS-3: what a new tenant is provisioned with. The slug identifies the request: provisioning
 * the same slug again returns the same tenant (or finishes it), never a second one.
 */
final readonly class ProvisioningRequest
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $ownerEmail,
        public string $planCode,
        public ?int $trialDays = null,
        public ?string $ownerName = null,
        public ?string $legalName = null,
        public string $timezone = 'Asia/Kolkata',
        public string $locale = 'en',
        public string $currency = 'INR',
        public string $country = 'IN',
    ) {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/', $slug) !== 1) {
            throw new DomainException('A tenant slug is 3–63 lower-case letters, digits and hyphens.');
        }

        if (in_array($slug, ['admin', 'portal', 'careers', 'invitations', 'organisations', 'api', 'platform', 'webhooks', 'files', 'health', 'livewire'], true)) {
            throw new DomainException("\"{$slug}\" is reserved.");
        }

        if (trim($name) === '') {
            throw new DomainException('A tenant needs a name.');
        }

        if (filter_var(User::normaliseEmail($ownerEmail), FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('The owner needs a valid email address.');
        }

        if ($trialDays !== null && ($trialDays < 1 || $trialDays > 90)) {
            throw new DomainException('A trial lasts 1 to 90 days.');
        }
    }

    /**
     * What makes a repeated request "the same" request.
     *
     * @return array{name: string, owner: string, plan: string}
     */
    public function fingerprint(): array
    {
        return ['name' => trim($this->name), 'owner' => User::normaliseEmail($this->ownerEmail), 'plan' => $this->planCode];
    }
}
