<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $topic
 * @property array<string, mixed> $payload
 * @property Carbon|null $published_at
 */
class OutboxEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['topic', 'payload', 'published_at'];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'published_at' => 'datetime',
    ];
}
