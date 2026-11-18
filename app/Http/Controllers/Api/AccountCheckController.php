<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountCheck;
use App\Services\AccountBalanceService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * End-of-day account check (super admin): type the balance the GCash app
 * actually shows, and the system records it against the balance it expected
 * (same figure as the Accounts page) so any difference is caught that day.
 */
class AccountCheckController extends Controller implements HasMiddleware
{
	public function __construct(private AccountBalanceService $balances) {}

	public static function middleware(): array
	{
		return [
			new Middleware('role:super_admin'),
		];
	}

	// GET /api/account-checks?method=gcash
	public function index(Request $request)
	{
		$branchId = $this->requireBranch($request);
		$method   = $this->method($request);

		$account = $this->balances->buildAccount($branchId, $method, now()->toDateString());

		$checks = AccountCheck::with('user:id,name')
			->where('branch_id', $branchId)
			->where('method', $method)
			->orderByDesc('created_at')
			->limit(60)
			->get();

		return response()->json([
			'method'        => $method,
			'expected'      => $account['balance'],
			'has_opening'   => $account['has_opening'],
			'checked_today' => $checks->first()?->created_at?->isToday() ?? false,
			'checks'        => $checks,
		]);
	}

	// POST /api/account-checks { method, counted_amount, note? }
	public function store(Request $request)
	{
		$branchId  = $this->requireBranch($request);
		$validated = $request->validate([
			'method'         => 'required|in:cash,gcash',
			'counted_amount' => 'required|numeric|min:0|max:99999999',
			'note'           => 'nullable|string|max:500',
		]);

		$expected = (float) $this->balances->buildAccount($branchId, $validated['method'], now()->toDateString())['balance'];
		$counted  = round((float) $validated['counted_amount'], 2);

		AccountCheck::create([
			'branch_id'       => $branchId,
			'method'          => $validated['method'],
			'counted_amount'  => $counted,
			'expected_amount' => round($expected, 2),
			'difference'      => round($counted - $expected, 2),
			'note'            => $validated['note'] ?? null,
			'user_id'         => $request->user()->id,
		]);

		$request->merge(['method' => $validated['method']]);

		return $this->index($request);
	}

	// DELETE /api/account-checks/{check} — remove a check typed in by mistake
	public function destroy(Request $request, AccountCheck $check)
	{
		$branchId = $this->requireBranch($request);
		if ($check->branch_id !== $branchId) {
			abort(404);
		}

		$check->delete();
		$request->merge(['method' => $check->method]);

		return $this->index($request);
	}

	private function requireBranch(Request $request): int
	{
		$branchId = $this->branchId($request);
		if ($branchId === null) {
			abort(422, 'Select a branch first — each branch has its own GCash account.');
		}

		return $branchId;
	}

	private function method(Request $request): string
	{
		return in_array($request->input('method'), ['cash', 'gcash'], true) ? $request->input('method') : 'gcash';
	}
}
