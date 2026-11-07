<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SimulatePaymentController;

Route::post('/v1/simulate/payment', SimulatePaymentController::class)
    ->middleware('demo.only');
