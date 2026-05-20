<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BankPayment;
use Illuminate\Support\Str;

class BankSimulator
{
    /**
     * Send payment to the bank partner.
     * Returns an array with mock response details.
     */
    public function sendPayment(BankPayment $payment): array
    {
        // Simple mock response
        return [
            'status' => 'pending',
            'external_reference' => 'BANK-TX-' . Str::uuid(),
            'message' => 'Payment initiated successfully',
        ];
    }
}
