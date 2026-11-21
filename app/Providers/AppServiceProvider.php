<?php

namespace App\Providers;

use App\Models\Preference;
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
        View::composer('mission.layout', function ($view): void {
            $view->with('unseenBrainCount', Preference::query()->active()->where('source', 'learned')->whereNull('seen_at')->count());
        });
    }
}
