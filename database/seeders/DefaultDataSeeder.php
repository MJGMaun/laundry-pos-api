<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultDataSeeder extends Seeder
{
	public function run(): void
	{
		// ── Loyalty tiers ──────────────────────────────────
		DB::table('loyalty_tiers')->insert([
			[
				'name'                => 'Bronze',
				'multiplier'          => 1.0,
				'min_spend_threshold' => 0,
				'is_default'          => true,
				'created_at'          => now(),
				'updated_at'          => now(),
			],
			[
				'name'                => 'Silver',
				'multiplier'          => 1.5,
				'min_spend_threshold' => 5000.00,
				'is_default'          => false,
				'created_at'          => now(),
				'updated_at'          => now(),
			],
			[
				'name'                => 'Gold',
				'multiplier'          => 2.0,
				'min_spend_threshold' => 15000.00,
				'is_default'          => false,
				'created_at'          => now(),
				'updated_at'          => now(),
			],
		]);

		// ── Sample services ────────────────────────────────

		$services = [
			['name' => 'Regular Wash',     'pricing_type' => 'per_kilo',  'price' => 50.00],
			['name' => 'Beddings',         'pricing_type' => 'per_piece', 'price' => 80.00],
			['name' => 'Suit / Blazer',    'pricing_type' => 'per_piece', 'price' => 250.00],
			['name' => 'Dress',            'pricing_type' => 'per_piece', 'price' => 200.00],
			['name' => 'Shirt Press',      'pricing_type' => 'per_piece', 'price' => 30.00],
			['name' => 'Pants Press',      'pricing_type' => 'per_piece', 'price' => 30.00],
			['name' => 'Curtains',         'pricing_type' => 'per_piece', 'price' => 150.00],
			['name' => 'Comforter (Large)', 'pricing_type' => 'flat_rate',  'price' => 350.00],
		];

		foreach ($services as $svc) {
			DB::table('services')->insert(array_merge($svc, [
				'is_active'  => true,
				'created_at' => now(),
				'updated_at' => now(),
			]));
		}

		// ── Expense categories ─────────────────────────────
		$expense_cats = [
			'Detergent & Supplies',
			'Water Bill',
			'Electric Bill',
			'Machine Maintenance',
			'Rent',
			'Wages',
			'Miscellaneous',
		];

		foreach ($expense_cats as $name) {
			DB::table('expense_categories')->insert([
				'name'       => $name,
				'created_at' => now(),
				'updated_at' => now(),
			]);
		}

		// ── Default settings ───────────────────────────────
		$settings = [
			// Shop info
			['key' => 'shop_name',    'value' => 'My Laundry Shop',      'group' => 'shop'],
			['key' => 'shop_address', 'value' => '123 Main St',          'group' => 'shop'],
			['key' => 'shop_phone',   'value' => '09171234567',          'group' => 'shop'],
			['key' => 'shop_tin',     'value' => '',                     'group' => 'shop'],

			// Loyalty
			['key' => 'loyalty_points_per_peso',   'value' => '1',      'group' => 'loyalty'],
			['key' => 'loyalty_redemption_value',   'value' => '0.50',  'group' => 'loyalty'],
			['key' => 'loyalty_min_redeem_points',  'value' => '100',   'group' => 'loyalty'],

			// Receipt
			['key' => 'receipt_footer', 'value' => 'Thank you for choosing us!', 'group' => 'receipt'],

			// Printer
			['key' => 'printer_type',       'value' => 'usb',           'group' => 'printer'],
			['key' => 'printer_device_path', 'value' => '/dev/usb/lp0',  'group' => 'printer'],
		];

		foreach ($settings as $s) {
			DB::table('settings')->insert(array_merge($s, [
				'created_at' => now(),
				'updated_at' => now(),
			]));
		}
	}
}
