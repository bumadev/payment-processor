<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\ApiFormRequest;

class SavePaymentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'payment_amount' => ['required', 'numeric', 'min:0.01'],
            'payment_gateway' => ['required', 'string', 'max:60'],
        ];
    }
}
