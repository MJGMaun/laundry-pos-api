<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyCashBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashBalanceController extends Controller
{
    public function show(Request $request): \Illuminate\Http\JsonResponse
    {
        $date     = $request->date ?? $this->businessToday();
        $branchId = $this->branchId($request);

        $record = DailyCashBalance::where('branch_id', $branchId)
            ->where('date', $date)
            ->with('setBy:id,name')
            ->first();

        // Net per-method totals (payments minus refunds). Payment timestamps are
        // UTC, so bucket by the business-time (PH) calendar date.
        $rows = DB::table('payments')
            ->join('orders', 'payments.order_id', '=', 'orders.id')
            ->where('orders.branch_id', $branchId)
            ->whereRaw($this->bizDateExpr('payments.created_at') . ' = ?', [$date])
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

        // Expenses for this date, split by payment method
        $expenseRows = DB::table('expenses')
            ->where('branch_id', $branchId)
            ->where('expense_date', $date)
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

        $startingBalance = (float) ($record?->starting_balance ?? 0);
        $cashNet         = round($net['cash'], 2);
        $gcashNet        = round($net['gcash'], 2);
        $totalInDrawer   = round($startingBalance + $cashNet - $cashExpenses, 2);
        $toRemitCash     = round($totalInDrawer - $startingBalance, 2);
        $toRemitGcash    = round($gcashNet - $gcashExpenses, 2);

        return response()->json([
            'date'             => $date,
            'starting_balance' => $startingBalance,
            'set_by'           => $record?->setBy?->name,
            'cash_in'          => $cashNet,
            'gcash_in'         => $gcashNet,
            'maya_in'          => round($net['maya'], 2),
            'card_in'          => round($net['card'], 2),
            'expenses'         => round($expenses, 2),
            'cash_expenses'    => round($cashExpenses, 2),
            'gcash_expenses'   => round($gcashExpenses, 2),
            'total_in_drawer'  => $totalInDrawer,
            'to_remit_cash'    => $toRemitCash,
            'to_remit_gcash'   => $toRemitGcash,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'date'             => 'required|date_format:Y-m-d',
            'starting_balance' => 'required|numeric|min:0',
        ]);

        DailyCashBalance::updateOrCreate(
            [
                'branch_id' => $this->branchId($request),
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
