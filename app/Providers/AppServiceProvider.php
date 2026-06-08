<?php

namespace App\Providers;

use App\Services\Integrations\InvestReadyConnectClient;
use App\Services\Integrations\PersonaClient;
use App\Services\Integrations\StripeClient;
use App\Services\Integrations\VerifyInvestorClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Integration clients have primitive constructor params that Laravel
        // can't autowire. Bind each as a singleton built from config().
        $this->app->singleton(PersonaClient::class, fn () => PersonaClient::fromConfig());
        $this->app->singleton(VerifyInvestorClient::class, fn () => VerifyInvestorClient::fromConfig());
        $this->app->singleton(InvestReadyConnectClient::class, fn () => InvestReadyConnectClient::fromConfig());
        $this->app->singleton(StripeClient::class, fn () => StripeClient::fromConfig());
    }

    public function boot(): void
    {
        //
    }
}
