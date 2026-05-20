<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollEvent;
use App\Jobs\ProcessPayrollEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

class PayrollWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'string', 'max:255'],
            'event_type' => ['required', 'string', 'in:employee_onboarded,employee_status_changed,salary_run'],
            'payload' => ['required', 'array'],
        ]);

        try {
            // Store the raw event
            $event = PayrollEvent::create([
                'external_event_id' => $validated['event_id'],
                'event_type' => $validated['event_type'],
                'payload' => $validated['payload'],
                'status' => 'received',
            ]);

            // Dispatch job to process the event asynchronously
            ProcessPayrollEvent::dispatch($event);

            return response()->json([
                'status' => 'accepted',
                'event_id' => $event->id,
            ], 202);

        } catch (QueryException $e) {
            // Check for duplicate external_event_id
            if ($e->errorInfo[1] === 1062 || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                // Return 202 Accepted since we already have this event (idempotent ingestion)
                $existing = PayrollEvent::where('external_event_id', $validated['event_id'])->firstOrFail();
                return response()->json([
                    'status' => 'accepted',
                    'event_id' => $existing->id,
                    'message' => 'Event already received',
                ], 202);
            }

            throw $e;
        }
    }
}
