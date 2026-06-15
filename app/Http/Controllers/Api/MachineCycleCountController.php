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
            ->withSum(['cycleCounts as prior_cycles' => function ($q) use ($date) {
                $q->where('date', '<', $date);
            }], 'cycle_count')
            ->withSum(['cycleCounts as month_cycles' => function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('date', [$monthStart, $monthEnd]);
            }], 'cycle_count')
            ->with(['cycleCounts' => function ($q) use ($date) {
                $q->where('date', $date)->with('recordedBy:id,name');
            }])
            ->get();

        $result = $machines->map(function ($machine) {
            $count = $machine->cycleCounts->first();
            $delta = $count?->cycle_count; // cycles added on this date (null if not recorded)

            // Meter reading the machine showed at the start of this date
            $previousTotal = $machine->initial_cycle_count + (int) $machine->prior_cycles;

            return [
                'id' => $machine->id,
                'name' => $machine->name,
                'type' => $machine->type,
                'cycle_count' => $delta,
                'previous_total' => $previousTotal,
                // Cumulative meter reading recorded for this date (what the admin types)
                'meter_reading' => $delta === null ? null : $previousTotal + $delta,
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
            'readings' => 'required|array|min:1',
            'readings.*.machine_id' => 'required|exists:machines,id',
            'readings.*.total_cycle_count' => 'required|integer|min:0',
        ]);

        $date = $validated['date'];

        // Tenant isolation: every machine must belong to the current branch
        $machineIds = collect($validated['readings'])->pluck('machine_id')->unique();
        $machines = $this->scopeToBranch(Machine::query(), $request)
            ->whereIn('id', $machineIds)
            ->get()
            ->keyBy('id');

        if ($machines->count() !== $machineIds->count()) {
            return response()->json(['message' => 'One or more machines do not belong to this branch.'], 403);
        }

        // Convert each meter reading into a daily delta (reading − previous total),
        // validating the whole batch before writing anything.
        $toSave = [];
        $errors = [];

        foreach ($validated['readings'] as $entry) {
            $machine = $machines[$entry['machine_id']];

            $priorDeltas = (int) MachineCycleCount::where('machine_id', $machine->id)
                ->where('date', '<', $date)
                ->sum('cycle_count');
            $previousTotal = $machine->initial_cycle_count + $priorDeltas;
            $delta = $entry['total_cycle_count'] - $previousTotal;

            if ($delta < 0) {
                $errors[] = "{$machine->name}: reading {$entry['total_cycle_count']} is below its previous total of {$previousTotal}.";

                continue;
            }

            $toSave[] = ['machine_id' => $machine->id, 'delta' => $delta];
        }

        if ($errors) {
            return response()->json(['message' => implode(' ', $errors)], 422);
        }

        foreach ($toSave as $entry) {
            MachineCycleCount::updateOrCreate(
                [
                    'machine_id' => $entry['machine_id'],
                    'date' => $date,
                ],
                [
                    'cycle_count' => $entry['delta'],
                    'recorded_by' => $request->user()->id,
                ]
            );
        }

        return $this->show($request);
    }
}
