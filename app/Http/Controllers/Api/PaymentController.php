<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CustomerBalanceService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller implements HasMiddleware
{
    public function __construct(private CustomerBalanceService $balances) {}

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
            'method'           => 'required|in:cash,gcash,balance',
            'type'             => 'nullable|in:payment,refund',
            'amount'           => 'required|numeric|min:0.01',
            'tendered'         => 'nullable|numeric|min:0',
            'reference_number' => 'nullable|string|max:255',
            'payment_date'     => 'nullable|date|before_or_equal:today',
            // Photo of the GCash receipt — the alternative to typing the reference.
            'receipt'          => 'nullable|image|max:10240',
            // Extra the customer sent on top of the amount applied to the order.
            'tip_amount'       => 'nullable|numeric|min:0|max:100000',
        ]);

        // Only admins may backdate a payment; silently ignore for other roles.
        if (! in_array($request->user()->role, ['super_admin', 'admin'])) {
            unset($validated['payment_date']);
        }

        $type   = $validated['type'] ?? 'payment';
        $method = $validated['method'];
        $amount = $validated['amount'];

        // Customer balance (prepaid deposits) is admin-only and needs a customer.
        if ($method === 'balance') {
            if (! in_array($request->user()->role, ['super_admin', 'admin'])) {
                abort(403, 'Only an admin can use customer balance.');
            }
            if (! $order->customer_id || ! $order->customer) {
                abort(422, 'This order has no customer, so customer balance cannot be used.');
            }
        }

        $tip = round((float) ($validated['tip_amount'] ?? 0), 2);
        if ($tip > 0 && ($type !== 'payment' || $method === 'balance')) {
            abort(422, 'A tip can only be added to a cash or GCash payment.');
        }

        // GCash needs proof that can be matched against the GCash app later:
        // at least the last 4 characters of the reference, or a receipt photo.
        if ($type === 'payment' && $method === 'gcash' && ! $request->hasFile('receipt')
            && strlen(trim((string) ($validated['reference_number'] ?? ''))) < 4) {
            abort(422, 'Enter at least the last 4 digits of the GCash reference number, or attach a photo of the receipt.');
        }

        // Cash payments require a tendered amount >= the payment amount
        if ($type === 'payment' && $method === 'cash') {
            $request->validate([
                'tendered' => "required|numeric|min:{$amount}",
            ]);
        }


        $payment = DB::transaction(function () use ($validated, $order, $type, $amount, $request, $tip) {
            // For refunds: cannot refund more than what was actually paid (net)
            if ($type === 'refund') {
                $existingPayments = $order->payments;
                $netPaid = $existingPayments->where('type', 'payment')->sum('amount')
                         - $existingPayments->where('type', 'refund')->sum('amount');

                if ($amount > $netPaid) {
                    abort(422, 'Refund amount exceeds net paid amount of ' . $netPaid);
                }
            }

            // Balance is exact money with no change, so never take more than is owed.
            if ($type === 'payment' && $validated['method'] === 'balance') {
                $existing = $order->payments()->get();
                $owed = (float) $order->total_amount
                      - ($existing->where('type', 'payment')->sum('amount') - $existing->where('type', 'refund')->sum('amount'));
                if ($amount > round($owed, 2) + 0.001) {
                    abort(422, 'Amount is more than the order balance due of ' . number_format(max($owed, 0), 2));
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
                'tip_amount'       => $tip,
                'tendered'         => $validated['tendered'] ?? null,
                'change_amount'    => $changeAmount,
                'reference_number' => isset($validated['reference_number']) ? trim($validated['reference_number']) : null,
                'receipt_path'     => $request->hasFile('receipt')
                    ? $request->file('receipt')->store('payment-receipts/' . now()->format('Y/m'), 'local')
                    : null,
            ]);

            // Admin backdating: cash balance / day summary key off the
            // payment's created_at, so a late-entered payment can land on
            // its real business date.
            if (!empty($validated['payment_date'])) {
                $payment->created_at = \Carbon\Carbon::parse($validated['payment_date'])->setTimeFrom(now());
                $payment->save();
            }

            if ($payment->method === 'balance') {
                $this->balances->recordPayment($payment, $order->customer, $order->branch_id, $request->user()->id);
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

        if (in_array($request->input('method'), ['cash', 'gcash', 'balance'], true)) {
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
                SUM(CASE WHEN payments.type = 'refund' THEN payments.amount ELSE 0 END) as total_refunds,
                SUM(payments.tip_amount) as total_tips
            ")
            ->first();

        $payments = $query
            ->orderByDesc('payments.created_at')
            ->select([
                'payments.id',
                'payments.method',
                'payments.type',
                'payments.amount',
                'payments.tip_amount',
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
                'total_tips'    => round((float) ($totals->total_tips ?? 0), 2),
            ],
        ] + $payments->toArray());
    }

    // GET /api/payments/{payment}/receipt — streams the GCash receipt photo.
    // Private disk: receipts show names and numbers, so no public URL.
    public function receipt(Request $request, Payment $payment)
    {
        $this->ensureBranch($request, $payment);

        if (! $payment->receipt_path || ! Storage::disk('local')->exists($payment->receipt_path)) {
            abort(404, 'No receipt photo for this payment.');
        }

        return Storage::disk('local')->response($payment->receipt_path);
    }

    // PATCH /api/payments/{payment}/method (admin-only via route middleware)
    // Corrects a payment recorded under the wrong method — usually GCash
    // tapped on what was really cash. Totals follow automatically since every
    // report sums by method; the original method and who changed it are kept.
    public function changeMethod(Request $request, Payment $payment)
    {
        $order = $this->ensureBranch($request, $payment);

        $validated = $request->validate([
            'method'           => 'required|in:cash,gcash',
            'reference_number' => 'nullable|string|max:255',
        ]);

        if ($payment->method === 'balance') {
            abort(422, 'A customer-balance payment cannot be switched. Delete it and record a new payment instead.');
        }
        if ($payment->method === $validated['method']) {
            abort(422, 'This payment is already ' . strtoupper($validated['method']) . '.');
        }

        $reference = trim((string) ($validated['reference_number'] ?? ''));
        if ($validated['method'] === 'gcash' && $payment->type === 'payment'
            && strlen($reference) < 4 && ! $payment->receipt_path) {
            abort(422, 'Enter at least the last 4 digits of the GCash reference number.');
        }

        $payment->fill([
            'method'              => $validated['method'],
            'method_changed_from' => $payment->method_changed_from ?? $payment->method,
            'method_changed_by'   => $request->user()->id,
            'method_changed_at'   => now(),
            // A match was against the old account; it no longer holds.
            'matched_at'          => null,
            'matched_by'          => null,
        ]);

        if ($validated['method'] === 'cash') {
            // Treat as exact cash: no change was handed back for it.
            $payment->tendered      = $payment->type === 'payment' ? $payment->amount : null;
            $payment->change_amount = $payment->type === 'payment' ? 0 : null;
        } else {
            $payment->tendered      = null;
            $payment->change_amount = null;
            if ($reference !== '') {
                $payment->reference_number = $reference;
            }
        }

        // created_at is untouched, so the payment stays on its business day.
        $payment->save();

        return response()->json(['data' => $payment->fresh()->load('methodChangedBy:id,name')]);
    }

    // PATCH /api/payments/{payment}/tip (admin-only via route middleware)
    // Adds or corrects the tip on an already-recorded payment — e.g. the
    // customer sent extra on GCash and nobody entered it at the counter.
    public function updateTip(Request $request, Payment $payment)
    {
        $this->ensureBranch($request, $payment);

        $validated = $request->validate([
            'tip_amount' => 'required|numeric|min:0|max:100000',
        ]);

        if ($payment->type !== 'payment' || ! in_array($payment->method, ['cash', 'gcash'], true)) {
            abort(422, 'A tip can only be added to a cash or GCash payment.');
        }

        $payment->tip_amount = round((float) $validated['tip_amount'], 2);
        // What the GCash app shows for this payment changed, so a previous
        // match no longer proves anything.
        if ($payment->isDirty('tip_amount')) {
            $payment->matched_at = null;
            $payment->matched_by = null;
        }
        $payment->save();

        return response()->json(['data' => $payment->fresh()]);
    }

    private function ensureBranch(Request $request, Payment $payment): Order
    {
        $order = $payment->order;
        $branchId = $this->branchId($request);

        if (! $order || ($branchId !== null && $order->branch_id !== $branchId)) {
            abort(404);
        }

        return $order;
    }

    // Soft-delete a payment (admin-only via route middleware), reversing its
    // side effects: customer total_spent and the auto-complete status flip.
    public function destroy(Request $request, Payment $payment)
    {
        $order = $payment->order;

        if (! $order || $order->branch_id !== $this->branchId($request)) {
            abort(404);
        }

        DB::transaction(function () use ($payment, $order, $request) {
            // Give back (or take back) customer balance a balance payment moved.
            if ($payment->method === 'balance' && $order->customer) {
                $this->balances->reversePayment($payment, $order->customer, $order->branch_id, $request->user()->id);
            }

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
