<?php

declare(strict_types=1);

use App\Waas\Exceptions\WaasMisconfigured;
use App\Waas\Http\HttpWaasClient;
use App\Waas\Mock\MockWaasClient;
use App\Waas\WaasClient;
use Illuminate\Support\Facades\Log;
use Tests\Support\WaasTestKit as Kit;

beforeEach(function () {
    config([
        'waas.driver' => 'mock',
        'waas.allow_mock_in_production' => false,
        'waas.base_url' => Kit::BASE_URL,
        'waas.api_key' => Kit::API_KEY,
    ]);
    app()->forgetInstance(WaasClient::class);
});

/** Config changed mid-test => the singleton must be dropped first. */
function resolveWaas(): WaasClient
{
    app()->forgetInstance(WaasClient::class);

    return app(WaasClient::class);
}

it('binds the mock by default', function () {
    expect(resolveWaas())->toBeInstanceOf(MockWaasClient::class);
});

it('binds the real client when configured', function () {
    config(['waas.driver' => 'real']);

    expect(resolveWaas())->toBeInstanceOf(HttpWaasClient::class);
});

it('real driver without credentials is WaasMisconfigured, never a silent mock', function (string $key) {
    config(['waas.driver' => 'real', $key => '']);
    app()->forgetInstance(WaasClient::class);

    Kit::thrown(fn () => app(WaasClient::class), WaasMisconfigured::class);
})->with(['waas.base_url', 'waas.api_key']);

it('an unknown driver is WaasMisconfigured', function () {
    config(['waas.driver' => 'banana']);
    app()->forgetInstance(WaasClient::class);

    Kit::thrown(fn () => app(WaasClient::class), WaasMisconfigured::class);
});

it('refuses the mock in production unless explicitly allowed', function () {
    $this->app->detectEnvironment(fn () => 'production');
    app()->forgetInstance(WaasClient::class);

    Kit::thrown(fn () => app(WaasClient::class), WaasMisconfigured::class);
});

it('allows the mock in production only with the explicit flag, and warns', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config(['waas.allow_mock_in_production' => true]);
    Log::spy();

    expect(resolveWaas())->toBeInstanceOf(MockWaasClient::class);
    Log::shouldHaveReceived('warning')->once();
});

it('the real client in production requires https', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config(['waas.driver' => 'real', 'waas.base_url' => 'http://waas.example.test']);
    app()->forgetInstance(WaasClient::class);

    Kit::thrown(fn () => app(WaasClient::class), WaasMisconfigured::class);

    config(['waas.base_url' => Kit::BASE_URL]);
    expect(resolveWaas())->toBeInstanceOf(HttpWaasClient::class);
});