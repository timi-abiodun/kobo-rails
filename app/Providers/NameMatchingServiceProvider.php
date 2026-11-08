<?php

namespace App\Providers;

use App\NameMatching\NameMatcher;
use Illuminate\Support\ServiceProvider;

class NameMatchingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No interface: it's pure string logic with no external dependency. The external piece
        // (the embedding scorer) gets an interface + mock/real binding in the embedding service provider.
        $this->app->singleton(NameMatcher::class, fn () => NameMatcher::fromConfig(config('name_matching')));
    }
}