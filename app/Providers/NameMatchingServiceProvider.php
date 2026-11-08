<?php

namespace App\Providers;

use App\NameMatching\AccountNameVerifier;
use App\NameMatching\HttpNameSimilarityScorer;
use App\NameMatching\NameMatcher;
use App\NameMatching\NameSimilarityScorer;
use App\NameMatching\NullNameSimilarityScorer;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

class NameMatchingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NameMatcher::class, fn () => NameMatcher::fromConfig(config('name_matching')));

        // bind (not singleton) so tests can flip the driver per test
        $this->app->bind(NameSimilarityScorer::class, fn ($app) => match (config('name_matching.embedding.driver')) {
            'null'  => new NullNameSimilarityScorer(),
            'http'  => new HttpNameSimilarityScorer(
                $app->make(HttpFactory::class),
                $app->make(LoggerInterface::class),
                config('name_matching.embedding'),
            ),
            default => throw new InvalidArgumentException('Unknown NAME_SCORER driver.'),
        });

        $this->app->bind(AccountNameVerifier::class, fn ($app) => new AccountNameVerifier(
            $app->make(NameMatcher::class),
            $app->make(NameSimilarityScorer::class),
            $app->make(LoggerInterface::class),
            (float) config('name_matching.embedding.low'),
        ));
    }
}