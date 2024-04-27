<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $balance_cents
 * @property int $version
 */
class Account extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'balance_cents', 'currency'];

    protected $casts = [
        'balance_cents' => 'integer',
        'version' => 'integer',
    ];
}
