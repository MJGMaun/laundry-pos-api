<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DailyCashBalance;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class CashBalanceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            // Day Summary is built on these same figures, so either page grants
            // the read. Setting the float belongs to Cash Balance alone.
            new Middleware('page:cash-balance|day-summary,view'),
            new Middleware('page:cash-balance,edit', only: ['store']),
        ];
    }

    /**
     * Applies the branch filter, including the all-branches case a super admin
     * gets when no branch is picked. That case has to be explicit: comparing
     * `branch_id = NULL` is never true in SQL, so every total silently came
     * back as zero. Test branches are excluded, matching ReportsController.
     */
    private function scopeBranch($query, ?int $branchId, string $column = 'branch_id')
    {
        if ($branchId !== null) {
            return $query->where($column, $branchId);
        }

        return $query->whereNotIn($column, Branch::where('is_test', true)->pluck('id'));
    }

    public function show(Request $request): \Illuminate\Http\JsonResponse
    {
        // Single day by default; date_from/date_to select a range. The
        // starting float and drawer total are per-day concepts, so they
        // only apply when the range collapses to one day.
        $dateFrom = $request->date_from ?? $request->date ?? now()->toDateString();
        $dateTo   = $request->date_to ?? $dateFrom;
        if ($dateTo < $dateFrom) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        $isRange  = $dateFrom !== $dateTo;
        $branchId = $this->branchId($request);

        // The starting float is per-branch and per-day. With a branch picked we
        // show that branch's record and who set it; across all branches the
        // only meaningful figure is the sum of their floats.
        $record          = null;
        $startingBalance = 0.0;

        if (!$isRange) {
            if ($branchId !== null) {
                $record = DailyCashBalance::where('branch_id', $branchId)
                    ->whereDate('date', $dateFrom)
                    ->with('setBy:id,name')
                    ->first();
                $startingBalance = (float) ($record?->starting_balance ?? 0);
            } else {
                $startingBalance = (float) $this->scopeBranch(
                    DailyCashBalance::whereDate('date', $dateFrom),
                    $branchId
                )->sum('starting_balance');
            }
        }

        // Net per-method totals (payments minus refunds)
        $rows = $this->scopeBranch(
            DB::table('payments')->join('orders', 'payments.order_id', '=', 'orders.id'),
            $branchId,
            'orders.branch_id'
        )
            ->whereDate('payments.created_at', '>=', $dateFrom)
            ->whereDate('payments.created_at', '<=', $dateTo)
            ->whereNull('payments.deleted_at')
            ->whereNull('orders.deleted_at')
            ->select('payments.method', 'payments.type', DB::raw('SUM(payments.amount) as total'))
            ->groupBy('payments.method', 'payments.type')
            ->get();

        $net = ['cash' => 0.0, 'gcash' => 0.0, 'maya' => 0.0, 'card' => 0.0];
        foreach ($rows as $row) {
            $sign = $row->type === 'refund' ? -1 : 1;
            if (array_key_exists($row->method, $net)) {
                $net[$row->method] += $sign * (float) $row->total;
            }
        }

        // Expenses for this range, split by payment method
        $expenseRows = $this->scopeBranch(DB::table('expenses'), $branchId)
            ->whereBetween('expense_date', [$dateFrom, $dateTo])
            ->whereNull('deleted_at')
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->get();

        $cashExpenses  = 0.0;
        $gcashExpenses = 0.0;
        foreach ($expenseRows as $row) {
            if ($row->payment_method === 'gcash') {
                $gcashExpenses += (float) $row->total;
            } else {
                $cashExpenses += (float) $row->total;
            }
        }
        $expenses = $cashExpenses + $gcashExpenses;

        // Itemized payments for this range — which order/customer made up the totals.
        $payments = $this->scopeBranch(
            DB::table('payments')
                ->join('orders', 'payments.order_id', '=', 'orders.id')
                ->leftJoin('customers', 'orders.customer_id', '=', 'customers.id'),
            $branchId,
            'orders.branch_id'
        )
            ->whereDate('payments.created_at', '>=', $dateFrom)
            ->whereDate('payments.created_at', '<=', $dateTo)
            ->whereNull('payments.deleted_at')
            ->whereNull('orders.deleted_at')
            ->orderBy('payments.created_at')
            ->get([
                'payments.id',
                'payments.method',
                'payments.type',
                'payments.amount',
                'payments.created_at',
                'orders.id as order_id',
                'orders.order_number',
                'orders.created_at as order_created_at',
                'customers.name as customer_name',
            ]);

        // Orders made in this range that still owe money — explains the gap
        // between orders taken and cash actually collected. Net paid counts
        // payments minus refunds, excluding soft-deleted payments.
        $netPaidSql = "COALESCE((SELECT SUM(CASE WHEN p.type = 'payment' THEN p.amount ELSE -p.amount END)
            FROM payments p WHERE p.order_id = orders.id AND p.deleted_at IS NULL), 0)";

        $unpaid = $this->scopeBranch(
            DB::table('orders')->leftJoin('customers', 'orders.customer_id', '=', 'customers.id'),
            $branchId,
            'orders.branch_id'
        )
            ->whereNull('orders.deleted_at')
            ->whereDate('orders.created_at', '>=', $dateFrom)
            ->whereDate('orders.created_at', '<=', $dateTo)
            ->whereRaw("{$netPaidSql} < orders.total_amount")
            ->orderBy('orders.created_at')
            ->selectRaw("
                orders.id as order_id,
                orders.order_number,
                orders.total_amount,
                orders.created_at,
                customers.name as customer_name,
                {$netPaidSql} as net_paid
            ")
            ->get()
            ->map(function ($row) {
                $row->balance_due = round((float) $row->total_amount - (float) $row->net_paid, 2);
                return $row;
            });

        $unpaidTotal = round($unpaid->sum('balance_due'), 2);

        $cashNet         = round($net['cash'], 2);
        $gcashNet        = round($net['gcash'], 2);
        $totalInDrawer   = round($startingBalance + $cashNet - $cashExpenses, 2);
        $toRemitCash     = round($totalInDrawer - $startingBalance, 2);
        $toRemitGcash    = round($gcashNet - $gcashExpenses, 2);

        return response()->json([
            'date'             => $dateFrom,
            'date_from'        => $dateFrom,
            'date_to'          => $dateTo,
            'is_range'         => $isRange,
            'starting_balance' => $isRange ? null : $startingBalance,
            'set_by'           => $record?->setBy?->name,
            'cash_in'          => $cashNet,
            'gcash_in'         => $gcashNet,
            'maya_in'          => round($net['maya'], 2),
            'card_in'          => round($net['card'], 2),
            'expenses'         => round($expenses, 2),
            'cash_expenses'    => round($cashExpenses, 2),
            'gcash_expenses'   => round($gcashExpenses, 2),
            'total_in_drawer'  => $isRange ? null : $totalInDrawer,
            'to_remit_cash'    => $toRemitCash,
            'to_remit_gcash'   => $toRemitGcash,
            'payments'         => $payments,
            'unpaid'           => $unpaid,
            'unpaid_total'     => $unpaidTotal,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'date'             => 'required|date_format:Y-m-d',
            'starting_balance' => 'required|numeric|min:0',
        ]);

        $branchId = $this->branchId($request);

        // A float belongs to one drawer, so there is nothing to write while
        // viewing all branches.
        if ($branchId === null) {
            return response()->json([
                'message' => 'Select a branch before setting its starting balance.',
            ], 422);
        }

        DailyCashBalance::updateOrCreate(
            [
                'branch_id' => $branchId,
                'date'      => $validated['date'],
            ],
            [
                'starting_balance' => $validated['starting_balance'],
                'set_by'           => $request->user()->id,
            ]
        );

        return $this->show($request);
    }
}
