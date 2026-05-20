<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PayrollEvent::query()->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $page = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (PayrollEvent $event) => [
                'id' => $event->id,
                'external_event_id' => $event->external_event_id,
                'event_type' => $event->event_type,
                'status' => $event->status,
                'attempts' => $event->attempts,
                'error_message' => $event->error_message,
                'payload' => $event->payload,
                'processed_at' => $event->processed_at?->toISOString(),
                'created_at' => $event->created_at?->toISOString(),
                'updated_at' => $event->updated_at?->toISOString(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
