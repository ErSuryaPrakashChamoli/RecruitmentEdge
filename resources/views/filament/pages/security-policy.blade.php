<x-filament-panels::page>
    <x-filament::section heading="Multi-factor authentication">
        @if ($this->mfaRequired())
            <p class="text-sm">Every member of this organisation must sign in with an authenticator app.</p>
        @else
            <p class="text-sm">MFA is required for privileged roles and permissions only. You can require it for every member.</p>
        @endif
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">A person who belongs to several organisations uses MFA everywhere if any of them requires it — switching organisations never removes the requirement.</p>
    </x-filament::section>
</x-filament-panels::page>
