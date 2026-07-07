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
            'payment_date'     => 'nullable|date|before_or_equal:today',
        ]);

        // Only admins may backdate a payment; silently ignore for other roles.
        if (! in_array($request->user()->role, ['super_admin', 'admin'])) {
            unset($validated['payment_date']);
        }

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

            // Admin backdating: cash balance / day summary key off the
            // payment's created_at, so a late-entered payment can land on
            // its real business date.
            if (!empty($validated['payment_date'])) {
                $payment->created_at = \Carbon\Carbon::parse($validated['payment_date'])->setTimeFrom(now());
                $payment->save();
            }

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

    // All payments for the active branch (admin-only via route middleware) —
    // date range, method, and order-number/customer search filters.
    public function all(Request $request)
    {
        $branchId = $this->branchId($request);
        $dateFrom = $request->date_from ?? now()->toDateString();
        $dateTo   = $request->date_to ?? $dateFrom;
        if ($dateTo < $dateFrom) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $query = Payment::query()
            ->join('orders', 'payments.order_id', '=', 'orders.id')
            ->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
            ->where('orders.branch_id', $branchId)
            ->whereNull('orders.deleted_at')
            ->whereDate('payments.created_at', '>=', $dateFrom)
            ->whereDate('payments.created_at', '<=', $dateTo);

        if (in_array($request->input('method'), ['cash', 'gcash'], true)) {
            $query->where('payments.method', $request->input('method'));
        }

        if ($q = trim((string) $request->q)) {
            $query->where(function ($w) use ($q) {
                $w->where('orders.order_number', 'like', "%{$q}%")
                  ->orWhere('customers.name', 'like', "%{$q}%");
            });
        }

        $totals = (clone $query)
            ->selectRaw("
                SUM(CASE WHEN payments.type = 'payment' THEN payments.amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN payments.type = 'refund' THEN payments.amount ELSE 0 END) as total_refunds
            ")
            ->first();

        $payments = $query
            ->orderByDesc('payments.created_at')
            ->select([
                'payments.id',
                'payments.method',
                'payments.type',
                'payments.amount',
                'payments.reference_number',
                'payments.created_at',
                'orders.id as order_id',
                'orders.order_number',
                'customers.name as customer_name',
            ])
            ->paginate(50);

        $totalPaid    = round((float) ($totals->total_paid ?? 0), 2);
        $totalRefunds = round((float) ($totals->total_refunds ?? 0), 2);

        return response()->json([
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'summary'   => [
                'total_paid'    => $totalPaid,
                'total_refunds' => $totalRefunds,
                'net'           => round($totalPaid - $totalRefunds, 2),
            ],
        ] + $payments->toArray());
    }

    // Soft-delete a payment (admin-only via route middleware), reversing its
    // side effects: customer total_spent and the auto-complete status flip.
    public function destroy(Request $request, Payment $payment)
    {
        $order = $payment->order;

        if (! $order || $order->branch_id !== $this->branchId($request)) {
            abort(404);
        }

        DB::transaction(function () use ($payment, $order) {
            // Reverse the customer spend recorded by store().
            if ($order->customer_id && $order->customer) {
                if ($payment->type === 'payment') {
                    $order->customer->decrement('total_spent', (float) $payment->amount);
                } else {
                    $order->customer->increment('total_spent', (float) $payment->amount);
                }
            }

            $payment->delete();

            // A completed order that is no longer fully paid goes back to
            // claimed (the inverse of the auto-complete in store()).
            $remaining = $order->payments()->get();
            $netPaid   = $remaining->where('type', 'payment')->sum('amount')
                       - $remaining->where('type', 'refund')->sum('amount');
            if ($order->status === 'completed' && $netPaid < (float) $order->total_amount) {
                $order->update(['status' => 'claimed']);
            }
        });

        return response()->json(['message' => 'Payment deleted.']);
    }
}
