<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\MachineCycleCount;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

class MachineCycleCountController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('role:admin'),
        ];
    }

    public function show(Request $request)
    {
        $date = $request->date ?? now()->toDateString();
        $month = Carbon::parse($date);
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        $machines = $this->scopeToBranch(Machine::query(), $request)
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->withSum('cycleCounts as recorded_cycles', 'cycle_count')
            ->withSum(['cycleCounts as month_cycles' => function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('date', [$monthStart, $monthEnd]);
            }], 'cycle_count')
            ->with(['cycleCounts' => function ($q) use ($date) {
                $q->where('date', $date)->with('recordedBy:id,name');
            }])
            ->get();

        $result = $machines->map(function ($machine) {
            $count = $machine->cycleCounts->first();

            return [
                'id' => $machine->id,
                'name' => $machine->name,
                'type' => $machine->type,
                'cycle_count' => $count?->cycle_count,
                'month_cycles' => (int) $machine->month_cycles,
                'total_cycles' => $machine->initial_cycle_count + (int) $machine->recorded_cycles,
                'recorded_by' => $count?->recordedBy?->name,
                'recorded_at' => $count?->updated_at,
            ];
        });

        // Running totals across all of the branch's machines (active or not)
        $branchMachineIds = $this->scopeToBranch(Machine::query(), $request)->pluck('id');

        $monthTotal = MachineCycleCount::whereIn('machine_id', $branchMachineIds)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->sum('cycle_count');

        // All-time includes each machine's meter reading when it was added
        $allTimeTotal = MachineCycleCount::whereIn('machine_id', $branchMachineIds)->sum('cycle_count')
            + Machine::whereIn('id', $branchMachineIds)->sum('initial_cycle_count');

        return response()->json([
            'date' => $date,
            'machines' => $result,
            'total_cycles' => $result->sum(fn ($m) => $m['cycle_count'] ?? 0),
            'month_total' => (int) $monthTotal,
            'all_time_total' => (int) $allTimeTotal,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'counts' => 'required|array|min:1',
            'counts.*.machine_id' => 'required|exists:machines,id',
            'counts.*.cycle_count' => 'required|integer|min:0',
        ]);

        // Tenant isolation: every machine must belong to the current branch
        $machineIds = collect($validated['counts'])->pluck('machine_id')->unique();
        $ownedCount = $this->scopeToBranch(Machine::query(), $request)
            ->whereIn('id', $machineIds)
            ->count();

        if ($ownedCount !== $machineIds->count()) {
            return response()->json(['message' => 'One or more machines do not belong to this branch.'], 403);
        }

        foreach ($validated['counts'] as $entry) {
            MachineCycleCount::updateOrCreate(
                [
                    'machine_id' => $entry['machine_id'],
                    'date' => $validated['date'],
                ],
                [
                    'cycle_count' => $entry['cycle_count'],
                    'recorded_by' => $request->user()->id,
                ]
            );
        }

        return $this->show($request);
    }
}
