<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasUuids;

    protected $fillable = [
        'account_id', 'reference', 'amount_cents', 'currency', 'status',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
    ];
}
