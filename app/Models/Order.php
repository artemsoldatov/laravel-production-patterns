<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $account_id
 * @property string $reference
 * @property int $amount_cents
 * @property string $status
 */
class Order extends Model
{
    use HasUuids;

    protected $fillable = [
        'account_id', 'reference', 'amount_cents', 'currency', 'status', 'stripe_session_id',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
    ];
}
