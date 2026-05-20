<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreditWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:deposit,payroll,fee,refund'],
            'description' => ['sometimes', 'string', 'max:255'],
            'reference_type' => ['sometimes', 'string', 'max:50'],
            'reference_id' => ['sometimes', 'uuid'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
