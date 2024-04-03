<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'balance_cents', 'currency'];

    protected $casts = [
        'balance_cents' => 'integer',
    ];
}
