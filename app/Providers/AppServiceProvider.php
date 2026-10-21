<?php

namespace App\Providers;

use App\Enums\ApplicationStatus;
use App\Models\AttendantApplication;
use App\Policies\AttendantApplicationPolicy;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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
        Paginator::useBootstrapFive();
        Gate::policy(AttendantApplication::class, AttendantApplicationPolicy::class);

        View::composer('layouts.partials.sidebar-admin', function ($view): void {
            $user = auth()->user();
            $count = $user && $user->is_active && $user->isAdmin()
                ? AttendantApplication::query()
                    ->where('status', ApplicationStatus::Pending->value)
                    ->count()
                : 0;

            $view->with('pendingApplicationsCount', $count);
        });
    }
}
