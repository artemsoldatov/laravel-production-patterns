<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Patterns\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'uuid', 'exists:accounts,id'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:255'],
        ]);

        $order = $this->orders->place(
            $request->string('account_id')->toString(),
            $request->integer('amount_cents'),
            $request->string('reference')->toString(),
        );

        return response()->json($order, 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Order::query()->findOrFail($id));
    }
}
