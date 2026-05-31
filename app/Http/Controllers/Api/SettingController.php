<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SettingController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			// Reading settings is needed by all roles (including cashier) for offline caching
			new Middleware('role:admin', only: ['update']),
		];
	}

	private const REGISTRY = [
		// shop
		'shop_name'                 => ['group' => 'shop',    'rules' => 'required|string|max:255'],
		'shop_address'              => ['group' => 'shop',    'rules' => 'nullable|string|max:500'],
		'shop_phone'                => ['group' => 'shop',    'rules' => 'nullable|string|max:20'],
		'shop_email'                => ['group' => 'shop',    'rules' => 'nullable|email|max:255'],

		// loyalty
		'loyalty_points_per_peso'   => ['group' => 'loyalty', 'rules' => 'required|numeric|min:0'],
		'loyalty_min_redeem_points' => ['group' => 'loyalty', 'rules' => 'required|integer|min:0'],

		// receipt
		'receipt_footer'            => ['group' => 'receipt', 'rules' => 'nullable|string|max:500'],
		'receipt_show_loyalty'      => ['group' => 'receipt', 'rules' => 'required|in:true,false'],

		// printer
		'printer_name'              => ['group' => 'printer', 'rules' => 'nullable|string|max:255'],

		// general
		'day_summary_enabled'       => ['group' => 'general', 'rules' => 'required|in:true,false'],
	];

	// Settings only a super admin may change (regular admins are blocked).
	private const SUPER_ADMIN_ONLY = ['day_summary_enabled'];

	// GET /api/settings?group=shop
	// Returns global settings merged with branch overrides (branch wins per key)
	public function index(Request $request)
	{
		$branchId = $this->branchId($request);

		$query = Setting::query();

		if ($request->filled('group')) {
			$query->where('group', $request->group);
		}

		// Fetch global settings + branch-specific overrides
		$query->where(function ($q) use ($branchId) {
			$q->whereNull('branch_id');
			if ($branchId) {
				$q->orWhere('branch_id', $branchId);
			}
		});

		$settings = $query->orderBy('group')->orderBy('key')->get();

		// Merge: branch-specific setting wins over global for the same key
		$merged = $settings
			->groupBy('key')
			->map(fn($items) => $items->sortByDesc('branch_id')->first())
			->values();

		$grouped = $merged->groupBy('group')->map(
			fn($items) => $items->pluck('value', 'key')
		);

		return response()->json([
			'settings' => $merged,
			'grouped'  => $grouped,
		]);
	}

	// PUT /api/settings/{key}
	// Upserts for the current branch context (null branch_id = global)
	public function update(Request $request, string $key)
	{
		if (in_array($key, self::SUPER_ADMIN_ONLY, true) && ! $request->user()->isSuperAdmin()) {
			return response()->json(['message' => 'You do not have permission to change this setting.'], 403);
		}

		$meta  = self::REGISTRY[$key] ?? null;
		$rules = $meta['rules'] ?? 'required|string|max:1000';

		$validated = $request->validate(['value' => $rules]);

		$value = is_bool($validated['value'])
			? ($validated['value'] ? '1' : '0')
			: (string) $validated['value'];

		$branchId = $this->branchId($request);

		$setting = Setting::updateOrCreate(
			['key' => $key, 'branch_id' => $branchId],
			[
				'value' => $value,
				'group' => $meta['group'] ?? $request->input('group', 'general'),
			]
		);

		$branchColumnMap = [
			'shop_name'    => 'name',
			'shop_address' => 'address',
			'shop_phone'   => 'phone',
			'shop_email'   => 'email',
		];

		if ($branchId && isset($branchColumnMap[$key])) {
			Branch::where('id', $branchId)->update([$branchColumnMap[$key] => $value ?: null]);
		}

		return response()->json($setting);
	}
}
