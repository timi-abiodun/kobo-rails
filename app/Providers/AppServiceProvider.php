<?php

namespace App\Providers;

use App\Rails\HydrogenRailClient;
use App\Rails\MockRailClient;
use App\Rails\PaymentRailClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentRailClient::class, function () {
            $railConfig = config('rails');

            if ($railConfig['mode'] !== 'hydrogen' || ! $railConfig['hydrogen']['api_key']) {
                return new MockRailClient;
            }

            $hydrogenClient = new HydrogenRailClient(
                $railConfig['hydrogen']['base_url'],
                $railConfig['hydrogen']['api_key']
            );

            return $hydrogenClient->health() ? $hydrogenClient : new MockRailClient;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
