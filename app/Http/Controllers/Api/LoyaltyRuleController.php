<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyRule;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class LoyaltyRuleController extends Controller implements HasMiddleware
{
    public function __construct(private LoyaltyService $loyaltyService) {}

    public static function middleware(): array
    {
        return [
            new Middleware('role:admin', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request)
    {
        $rules = LoyaltyRule::where('branch_id', $this->branchId($request))
            ->orderBy('every_n_stamps')
            ->get();

        return response()->json($rules);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'every_n_stamps'     => 'required|integer|min:1',
            'reward_type'        => 'required|in:free_load,free_item',
            'reward_description' => 'required|string|max:200',
            'service_id'         => 'nullable|exists:services,id',
            'is_active'          => 'boolean',
            'reset_stamps'       => 'boolean',
        ]);

        $branchId = $this->branchId($request);

        if (!empty($validated['reset_stamps'])) {
            $this->loyaltyService->resetAllStamps($branchId, $request->user()->id);
        }

        unset($validated['reset_stamps']);

        $rule = LoyaltyRule::create(array_merge($validated, ['branch_id' => $branchId]));

        return response()->json($rule, 201);
    }

    public function update(Request $request, LoyaltyRule $loyaltyRule)
    {
        $validated = $request->validate([
            'every_n_stamps'     => 'sometimes|integer|min:1',
            'reward_type'        => 'sometimes|in:free_load,free_item',
            'reward_description' => 'sometimes|string|max:200',
            'service_id'         => 'nullable|exists:services,id',
            'is_active'          => 'boolean',
        ]);

        $loyaltyRule->update($validated);

        return response()->json($loyaltyRule);
    }

    public function destroy(LoyaltyRule $loyaltyRule)
    {
        $loyaltyRule->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
