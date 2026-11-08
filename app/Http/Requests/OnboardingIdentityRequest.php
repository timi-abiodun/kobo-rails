<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OnboardingIdentityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Tolerate "222 0000 0001" / "222-0000-0001". Only whitespace and hyphens are stripped.
        foreach (['bvn', 'nin'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => preg_replace('/[\s-]+/', '', $this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            // 'string' on purpose: a JSON number would silently drop leading zeros.
            'bvn' => ['required', 'string', 'regex:/^\d{11}$/D'],
            'nin' => ['nullable', 'string', 'regex:/^\d{11}$/D'],
        ];
    }

    /** Static messages only. Never use :input here. */
    public function messages(): array
    {
        return [
            'bvn.required' => 'BVN is required.',
            'bvn.string'   => 'BVN must be sent as a string of digits.',
            'bvn.regex'    => 'BVN must be exactly 11 digits.',
            'nin.string'   => 'NIN must be sent as a string of digits.',
            'nin.regex'    => 'NIN must be exactly 11 digits.',
        ];
    }
}