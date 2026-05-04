<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SettingController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:admin'),
		];
	}

	/**
	 * Known settings: defines the group and validation rules per key.
	 * Unknown keys fall back to a generic string rule.
	 */
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
		'receipt_show_loyalty'      => ['group' => 'receipt', 'rules' => 'required|boolean'],

		// printer
		'printer_name'              => ['group' => 'printer', 'rules' => 'nullable|string|max:255'],
	];

	// GET /api/settings          → all settings
	// GET /api/settings?group=shop → filtered by group
	public function index(Request $request)
	{
		$query = Setting::query();

		if ($request->filled('group')) {
			$query->where('group', $request->group);
		}

		$settings = $query->orderBy('group')->orderBy('key')->get();

		// Return as flat key→value map grouped by group for convenience
		$grouped = $settings->groupBy('group')->map(
			fn($items) => $items->pluck('value', 'key')
		);

		return response()->json([
			'settings' => $settings,
			'grouped'  => $grouped,
		]);
	}

	// PUT /api/settings/{key}
	public function update(Request $request, string $key)
	{
		$meta  = self::REGISTRY[$key] ?? null;
		$rules = $meta['rules'] ?? 'required|string|max:1000';

		$validated = $request->validate(['value' => $rules]);

		// Cast booleans to string for storage
		$value = is_bool($validated['value'])
			? ($validated['value'] ? '1' : '0')
			: (string) $validated['value'];

		$setting = Setting::updateOrCreate(
			['key' => $key],
			[
				'value' => $value,
				'group' => $meta['group'] ?? $request->input('group', 'general'),
			]
		);

		return response()->json($setting);
	}
}
