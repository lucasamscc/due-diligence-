<?php

namespace App\Providers;

use App\Sources\SourceRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SourceRegistry::class);
    }

    public function boot(): void
    {
        // Token bucket por fornecedor, no Redis (cache store). Estourou → o middleware
        // RateLimited faz release(delay) e a mensagem espera numa delay queue com TTL,
        // sem ocupar worker. Ver "brasilapi.delay.NNNN" aparecer na Management UI.
        foreach (app(SourceRegistry::class)->providers() as $provider) {
            RateLimiter::for(
                "source:{$provider}",
                fn () => Limit::perMinute(config("sources.{$provider}.rate_per_minute", 60))->by($provider),
            );
        }
    }
}
