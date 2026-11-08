<?php

namespace App\NameMatching;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Contract assumed with the FastAPI service (TODO confirm with your teammate):
 *   POST {url}{path}  {"name_a": "...", "name_b": "..."}  ->  {"score": 0.0..1.0}
 * Names are PII (not BVN/NIN). Keep this service internal; use TLS if it isn't on localhost.
 */
final class HttpNameSimilarityScorer implements NameSimilarityScorer
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $log,
        private readonly array $config,
    ) {}

    public function score(string $registeredName, string $bankName): ?float
    {
        try {
            $request = $this->http
                ->baseUrl($this->config['url'])
                ->acceptJson()
                ->asJson()
                ->connectTimeout($this->config['connect_timeout'])
                ->timeout($this->config['timeout']);

            if (! empty($this->config['token'])) {
                $request = $request->withToken($this->config['token']);
            }

            $response = $request->post($this->config['path'], [
                'name_a' => $registeredName,
                'name_b' => $bankName,
            ]);
        } catch (ConnectionException) {
            $this->log->warning('name_scorer_unavailable', ['reason' => 'connection']); // no names, no message
            return null;
        }

        if ($response->failed()) {
            $this->log->warning('name_scorer_unavailable', ['reason' => 'http_status', 'status' => $response->status()]);
            return null;
        }

        $score = $response->json('score');

        $valid = (is_int($score) || is_float($score)) && is_finite($score) && $score >= 0.0 && $score <= 1.0;

        if (! $valid) {
            $this->log->warning('name_scorer_unavailable', ['reason' => 'malformed_response']);
            return null;
        }

        return (float) $score;
    }
}