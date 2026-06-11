<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class MachineController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('role:admin'),
        ];
    }

    public function index(Request $request)
    {
        $machines = $this->scopeToBranch(Machine::query(), $request)
            ->withSum('cycleCounts as recorded_cycles', 'cycle_count')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        // Lifetime total = meter reading when added + everything recorded since
        $machines->each(function ($machine) {
            $machine->recorded_cycles = (int) $machine->recorded_cycles;
            $machine->total_cycles = $machine->initial_cycle_count + $machine->recorded_cycles;
        });

        return response()->json($machines);
    }

    public function store(Request $request)
    {
        $branchId = $this->branchId($request);

        if ($branchId === null) {
            return response()->json(['message' => 'Select a branch first.'], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:washer,dryer',
            'initial_cycle_count' => 'nullable|integer|min:0',
        ]);

        $validated['initial_cycle_count'] = $validated['initial_cycle_count'] ?? 0;

        $validated['branch_id'] = $branchId;

        return response()->json(Machine::create($validated), 201);
    }

    public function update(Request $request, Machine $machine)
    {
        $this->authorizeBranch($request, $machine);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'type' => 'sometimes|in:washer,dryer',
            'initial_cycle_count' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $machine->fill($validated)->save();

        return response()->json($machine);
    }

    public function destroy(Request $request, Machine $machine)
    {
        $this->authorizeBranch($request, $machine);

        $machine->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }

    private function authorizeBranch(Request $request, Machine $machine): void
    {
        $branchId = $this->branchId($request);

        if ($branchId !== null && $machine->branch_id !== $branchId) {
            abort(404);
        }
    }
}
