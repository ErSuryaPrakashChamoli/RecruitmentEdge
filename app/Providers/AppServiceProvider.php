<?php

namespace App\Providers;

use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Spatie's Role model lives outside App\Models, so Laravel's policy auto-discovery
        // (which only replaces a "Models" namespace segment) can't find RolePolicy on its own.
        Gate::policy(Role::class, RolePolicy::class);
    }
}
