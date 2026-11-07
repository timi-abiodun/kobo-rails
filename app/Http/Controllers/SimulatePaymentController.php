<?php

namespace App\Http\Controllers;

use App\Enums\PaymentChannel;
use App\Http\Requests\SimulatePaymentRequest;
use App\Rails\PaymentRailClient;
use Illuminate\Http\JsonResponse;

class SimulatePaymentController extends Controller
{
    public function __invoke(SimulatePaymentRequest $request, PaymentRailClient $rail): JsonResponse
    {
        $inflow = $rail->simulatePayment(
            $request->string('nuban')->toString(),
            $request->integer('amount_kobo'),
            $request->enum('channel', PaymentChannel::class) ?? PaymentChannel::POS,
            $request->input('reference'),
        );

        return response()->json(['status' => 'success', 'simulated' => true, 'data' => $inflow->toArray()]);
    }
}
