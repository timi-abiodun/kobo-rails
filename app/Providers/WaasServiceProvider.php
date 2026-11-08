<?php

declare(strict_types=1);

namespace App\Providers;

use App\Identity\IdentityHasher;
use App\Waas\Exceptions\WaasMisconfigured;
use App\Waas\Http\HttpWaasClient;
use App\Waas\Mock\MockWaasClient;
use App\Waas\WaasClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Resolves WaasClient once (singleton) from config('waas.driver').
 * Tests that flip waas.driver (or allow_mock_in_production) MUST call
 * app()->forgetInstance(WaasClient::class) before resolving again, or the old client is reused.
 */
final class WaasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WaasClient::class, function (Application $app): WaasClient {
            $config = (array) $app['config']->get('waas', []);

            return match ($config['driver'] ?? null) {
                'mock' => $this->makeMock($app, $config),
                'real' => $this->makeReal($app, $config),
                default => throw new WaasMisconfigured('Unknown WAAS_DRIVER: expected "mock" or "real".'),
            };
        });
    }

    /** @param array<string, mixed> $config */
    private function makeMock(Application $app, array $config): WaasClient
    {
        if ($app->isProduction()) {
            if (! ($config['allow_mock_in_production'] ?? false)) {
                throw new WaasMisconfigured(
                    'Mock WaaS client refused in production. Set WAAS_DRIVER=real, or WAAS_ALLOW_MOCK_IN_PRODUCTION=true for an explicit demo deploy.'
                );
            }
            Log::warning('WaaS mock driver is active in production (explicitly allowed).');
        }

        return new MockWaasClient($app->make(IdentityHasher::class), (array) ($config['mock'] ?? []));
    }

    /** @param array<string, mixed> $config */
    private function makeReal(Application $app, array $config): WaasClient
    {
        $baseUrl = (string) ($config['base_url'] ?? '');
        $apiKey = (string) ($config['api_key'] ?? '');

        if ($baseUrl === '' || $apiKey === '') {
            throw new WaasMisconfigured('WAAS_BASE_URL and WAAS_API_KEY are required when WAAS_DRIVER=real.');
        }
        if ($app->isProduction() && ! str_starts_with($baseUrl, 'https://')) {
            throw new WaasMisconfigured('WAAS_BASE_URL must use https in production.');
        }

        return new HttpWaasClient(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            timeouts: (array) ($config['timeouts'] ?? []),
            capabilities: (array) ($config['capabilities'] ?? []),
        );
    }
}