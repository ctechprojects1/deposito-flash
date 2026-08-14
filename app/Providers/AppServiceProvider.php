<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // HostGator costuma rodar MySQL mais antigo (< 5.7.7). Sem isto, o
        // `migrate` quebra com "Specified key was too long" ao usar utf8mb4.
        Schema::defaultStringLength(191);

        // Em produção (atrás do SSL do cPanel/AutoSSL) força HTTPS nas URLs
        // geradas — evita mixed content e links quebrados.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Rate limiter usado pelo grupo de middleware "api" (throttle:api).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
