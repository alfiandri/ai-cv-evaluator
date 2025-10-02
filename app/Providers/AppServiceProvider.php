<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\{LlmClientInterface, GeminiLlmClient, VectorStore};

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LlmClientInterface::class, fn() => new GeminiLlmClient());
        $this->app->singleton(VectorStore::class, fn($app) => new VectorStore($app->make(LlmClientInterface::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
