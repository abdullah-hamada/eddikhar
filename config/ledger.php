<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bank payment reconciliation
    |--------------------------------------------------------------------------
    |
    | Payments in initiated or pending status older than this many minutes
    | are picked up by ledger:reconcile-payments.
    |
    */

    'reconcile_after_minutes' => (int) env('LEDGER_RECONCILE_AFTER_MINUTES', 30),

];
