<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use App\Waas\WaasClient;

abstract class TestCase extends BaseTestCase
{
    public WaasClient $client;
}
