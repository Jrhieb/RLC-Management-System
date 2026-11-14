<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAccountApproval;
use App\Http\Middleware\EnsureStaffAccess;
use App\Http\Middleware\RequirePasswordConfirmation;
use App\Mail\BrevoTransport;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

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
        $this->configureDefaults();
        Mail::extend('brevo', fn (array $config = []): BrevoTransport => new BrevoTransport((string) ($config['key'] ?? '')));
        Livewire::addPersistentMiddleware([
            EnsureAccountApproval::class, EnsureStaffAccess::class,
            EnsureEmailIsVerified::class, RequirePasswordConfirmation::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
