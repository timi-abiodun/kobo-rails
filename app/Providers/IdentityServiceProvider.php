<?php

namespace App\Providers;

use App\Identity\Contracts\IdentityVerifier;
use App\Identity\DojahIdentityVerifier;
use App\Identity\IdentityHasher;
use App\Identity\MockIdentityVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IdentityHasher::class, fn () => new IdentityHasher(
            (string) config('identity.hash.pepper'),
            (int) config('identity.hash.version'),
        ));

        // bind (not singleton) so tests can flip config per test
        $this->app->bind(IdentityVerifier::class, fn ($app) => match (config('identity.driver')) {
            'mock'  => new MockIdentityVerifier(),
            'dojah' => new DojahIdentityVerifier($app->make(HttpFactory::class), config('identity.dojah')),
            default => throw new InvalidArgumentException('Unknown IDENTITY_VERIFIER driver.'),
        });
    }
}