<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Load extends Model
{
	use SoftDeletes;
	protected $fillable = [
		'order_id',
		'parent_load_id',
		'service_id',
		'service_name_snapshot',
		'unit_price_snapshot',
		'quantity',
		'line_total',
		'status',
		'notes',
	];

	protected $casts = [
		'unit_price_snapshot' => 'decimal:2',
		'quantity' => 'decimal:2',
		'line_total' => 'decimal:2',
	];

	// The service/category relations are eager-loaded only to compute the
	// order's load_count; keep them out of the serialized load payload.
	protected $hidden = ['service'];

	public function order()
	{
		return $this->belongsTo(Order::class);
	}

	public function service()
	{
		return $this->belongsTo(Service::class);
	}

	// Self-referencing: an add-on load belongs to a primary laundry load,
	// which in turn has many add-on loads.
	public function parent()
	{
		return $this->belongsTo(Load::class, 'parent_load_id');
	}

	public function addons()
	{
		return $this->hasMany(Load::class, 'parent_load_id');
	}

	/**
	 * Create loads for an order, linking add-on loads to their parent.
	 *
	 * Each $resolved item: service (Service), line_total, quantity, notes,
	 * and the linkage hints _key (this load's client key), parent_key (the
	 * client key of a parent created in the same batch) and/or parent_load_id
	 * (an already-existing parent load). Primaries are created first so their
	 * new ids can be mapped from _key before add-ons are inserted.
	 */
	public static function createWithAddons(Order $order, array $resolved): void
	{
		$keyToId = [];

		// First pass: primary loads (no parent linkage).
		foreach ($resolved as $r) {
			if (!empty($r['parent_key']) || !empty($r['parent_load_id'])) {
				continue;
			}
			$load = $order->loads()->create(self::loadAttributes($r));
			if (!empty($r['_key'])) {
				$keyToId[$r['_key']] = $load->id;
			}
		}

		// Second pass: add-ons linked to a parent (new via key, or existing id).
		foreach ($resolved as $r) {
			if (empty($r['parent_key']) && empty($r['parent_load_id'])) {
				continue;
			}
			$parentId = $keyToId[$r['parent_key'] ?? ''] ?? $r['parent_load_id'] ?? null;
			$order->loads()->create(self::loadAttributes($r) + ['parent_load_id' => $parentId]);
		}
	}

	private static function loadAttributes(array $r): array
	{
		return [
			'service_id'             => $r['service']->id,
			'service_name_snapshot'  => $r['service']->name,
			'unit_price_snapshot'    => $r['service']->price,
			'quantity'               => $r['quantity'],
			'line_total'             => $r['line_total'],
			'notes'                  => $r['notes'] ?? null,
		];
	}
}
