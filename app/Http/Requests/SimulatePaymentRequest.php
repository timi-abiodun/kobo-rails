<?php

namespace App\Http\Requests;

use App\Enums\PaymentChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class SimulatePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string|Enum>>
     */
    public function rules(): array
    {
        return [
            'nuban' => ['required', 'string', 'digits:10'],
            'amount_kobo' => ['bail', 'required', 'integer:strict', 'min:1', 'max:10000000000'],
            'channel' => ['sometimes', 'string', Rule::enum(PaymentChannel::class)],
            'reference' => ['sometimes', 'nullable', 'string', 'alpha_dash:ascii', 'max:255'],
        ];
    }
}
