<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('role:admin,cashier,staff'),
        ];
    }

    public function index(Order $order)
    {
        $payments = $order->payments()->latest()->get();

        $totalPaid    = $payments->where('type', 'payment')->sum('amount');
        $totalRefunds = $payments->where('type', 'refund')->sum('amount');
        $netPaid      = $totalPaid - $totalRefunds;

        return response()->json([
            'data'          => $payments,
            'summary' => [
                'order_total'   => $order->total_amount,
                'total_paid'    => round($totalPaid, 2),
                'total_refunds' => round($totalRefunds, 2),
                'net_paid'      => round($netPaid, 2),
                'balance_due'   => round(max($order->total_amount - $netPaid, 0), 2),
                'is_paid'       => $netPaid >= $order->total_amount,
            ],
        ]);
    }

    public function store(Request $request, Order $order)
    {
        $validated = $request->validate([
            'method'           => 'required|in:cash,gcash',
            'type'             => 'nullable|in:payment,refund',
            'amount'           => 'required|numeric|min:0.01',
            'tendered'         => 'nullable|numeric|min:0',
            'reference_number' => 'nullable|string|max:255',
        ]);

        $type   = $validated['type'] ?? 'payment';
        $method = $validated['method'];
        $amount = $validated['amount'];

        // Cash payments require a tendered amount >= the payment amount
        if ($type === 'payment' && $method === 'cash') {
            $request->validate([
                'tendered' => "required|numeric|min:{$amount}",
            ]);
        }


        $payment = DB::transaction(function () use ($validated, $order, $type, $amount) {
            // For refunds: cannot refund more than what was actually paid (net)
            if ($type === 'refund') {
                $existingPayments = $order->payments;
                $netPaid = $existingPayments->where('type', 'payment')->sum('amount')
                         - $existingPayments->where('type', 'refund')->sum('amount');

                if ($amount > $netPaid) {
                    abort(422, 'Refund amount exceeds net paid amount of ' . $netPaid);
                }
            }

            $changeAmount = null;
            if ($type === 'payment' && $validated['method'] === 'cash' && isset($validated['tendered'])) {
                $changeAmount = round($validated['tendered'] - $amount, 2);
            }

            $payment = Payment::create([
                'order_id'         => $order->id,
                'method'           => $validated['method'],
                'type'             => $type,
                'amount'           => $amount,
                'tendered'         => $validated['tendered'] ?? null,
                'change_amount'    => $changeAmount,
                'reference_number' => $validated['reference_number'] ?? null,
            ]);

            // After recording, recalculate net paid
            $allPayments  = $order->payments()->get();
            $totalPaid    = $allPayments->where('type', 'payment')->sum('amount');
            $totalRefunds = $allPayments->where('type', 'refund')->sum('amount');
            $netPaid      = $totalPaid - $totalRefunds;

            // Auto-complete: a claimed order that becomes fully paid no longer
            // needs a manual "Complete" click.
            if ($type === 'payment' && $order->status === 'claimed' && $netPaid >= $order->total_amount) {
                $order->update(['status' => 'completed']);
            }

            // Update customer total_spent by the actual payment/refund amount
            if ($order->customer_id) {
                $customer = $order->customer;
                if ($customer) {
                    if ($type === 'payment') {
                        $customer->increment('total_spent', $amount);
                    } else {
                        $customer->decrement('total_spent', $amount);
                    }

                    // Increment visits only the first time the order becomes fully paid
                    $previousNet = $netPaid - ($type === 'payment' ? $amount : -$amount);
                    if ($type === 'payment' && $netPaid >= $order->total_amount && $previousNet < $order->total_amount) {
                        $customer->increment('total_visits');
                    }
                }
            }

            return $payment;
        });

        $allPayments  = $order->payments()->get();
        $totalPaid    = $allPayments->where('type', 'payment')->sum('amount');
        $totalRefunds = $allPayments->where('type', 'refund')->sum('amount');
        $netPaid      = $totalPaid - $totalRefunds;

        return response()->json([
            'data'    => $payment,
            'summary' => [
                'order_total'   => $order->total_amount,
                'total_paid'    => round($totalPaid, 2),
                'total_refunds' => round($totalRefunds, 2),
                'net_paid'      => round($netPaid, 2),
                'balance_due'   => round(max($order->total_amount - $netPaid, 0), 2),
                'change'        => $payment->change_amount,
                'is_paid'       => $netPaid >= $order->total_amount,
            ],
        ], 201);
    }
}
