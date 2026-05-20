<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BankPayment;
use App\Services\BankService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBankPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;
    public function __construct(
        public readonly BankPayment $payment,
    ) {
        $this->afterCommit = true;
    }

    public function handle(BankService $bankService): void
    {
        $bankService->sendToBank($this->payment);
    }
}
