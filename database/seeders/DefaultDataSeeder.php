<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultDataSeeder extends Seeder
{
    public function run(): void
    {
        // ── Default branch ─────────────────────────────────
        $branchId = DB::table('branches')->insertGetId([
            'name' => 'Main Branch',
            'address' => '123 Main St',
            'phone' => '09171234567',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── Loyalty tiers ──────────────────────────────────
        DB::table('loyalty_tiers')->insert([
            [
                'name' => 'Bronze',
                'multiplier' => 1.0,
                'min_spend_threshold' => 0,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Silver',
                'multiplier' => 1.5,
                'min_spend_threshold' => 5000.00,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Gold',
                'multiplier' => 2.0,
                'min_spend_threshold' => 15000.00,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // ── Sample services ────────────────────────────────

        $services = [
            ['name' => 'ALL IN - Light Items',                       'pricing_type' => 'flat_rate', 'price' => 219.00],
            ['name' => 'ALL IN - Heavy Items',                       'pricing_type' => 'flat_rate', 'price' => 229.00],
            ['name' => 'Wash Only',                                  'pricing_type' => 'flat_rate',  'price' => 70.00],
            ['name' => 'Dry Only',                                   'pricing_type' => 'flat_rate',  'price' => 75.00],
            ['name' => 'Fold Only',                                  'pricing_type' => 'flat_rate', 'price' => 30.00],
            ['name' => 'Add dry',                                    'pricing_type' => 'flat_rate', 'price' => 20.00],
            ['name' => 'Add superwash',                              'pricing_type' => 'flat_rate', 'price' => 15.00],
            ['name' => 'Add detergent',                              'pricing_type' => 'flat_rate', 'price' => 18.00],
            ['name' => 'Add fabcon',                                 'pricing_type' => 'flat_rate', 'price' => 12.00],
            ['name' => 'Add colorsafe',                              'pricing_type' => 'flat_rate', 'price' => 8.00],
            ['name' => 'Comforter (Single 1-2pcs - Queen 1pc)',      'pricing_type' => 'flat_rate', 'price' => 229.00],
            ['name' => 'Comforter (King 1pc)',                       'pricing_type' => 'flat_rate', 'price' => 289.00],
        ];

        foreach ($services as $svc) {
            DB::table('services')->insert(array_merge($svc, [
                'is_active' => true,
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
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ── Default settings (global — branch_id = null) ──
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
            ['key' => 'printer_type',        'value' => 'usb',          'group' => 'printer'],
            ['key' => 'printer_device_path',  'value' => '/dev/usb/lp0', 'group' => 'printer'],
        ];

        foreach ($settings as $s) {
            DB::table('settings')->insert(array_merge($s, [
                'branch_id' => null,   // null = global default
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
