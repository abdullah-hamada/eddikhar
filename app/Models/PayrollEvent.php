<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PayrollEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'external_event_id',
        'event_type',
        'payload',
        'status',
        'processed_at',
        'attempts',
        'error_message',
    ];

    protected $attributes = [
        'status' => 'received',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
