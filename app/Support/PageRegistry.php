<?php

namespace App\Support;

/**
 * Every page a branch can grant or withhold, and the access each role has by
 * default. The defaults reproduce the role rules the app already shipped with,
 * so installing this system changes nothing until a branch overrides a row.
 *
 * Two levels of access, deliberately not four:
 *   view — the page loads and its data can be read
 *   edit — the page's create/update/delete actions are allowed
 * "Can delete but not update" has never been a real request here, and every
 * extra verb multiplies the configuration surface by the number of pages.
 *
 * `configurable => false` marks pages that stay super-admin-only whatever a
 * branch does: they cross branch boundaries or can destroy data, so handing
 * them to a cashier is never a per-branch decision.
 */
class PageRegistry
{
    public const ROLES = ['admin', 'cashier', 'staff'];

    /**
     * page key => [label, group, configurable, defaults[role => [view, edit]]]
     */
    public static function all(): array
    {
        return [
            // ── Front of house ────────────────────────────────────────────
            'pos' => self::page('POS', 'Operations', [
                'admin'   => [true, true],
                'cashier' => [true, true],
                'staff'   => [true, true],
            ]),
            'orders' => self::page('Orders', 'Operations', [
                'admin'   => [true, true],
                'cashier' => [true, true],
                'staff'   => [true, true],
            ]),
            'customers' => self::page('Customers', 'Operations', [
                'admin'   => [true, true],
                'cashier' => [true, true],
                'staff'   => [true, true],
            ]),
            'messages' => self::page('Messages', 'Operations', [
                'admin'   => [true, true],
                'cashier' => [true, true],
                'staff'   => [true, true],
            ]),
            'schedule' => self::page('Schedule', 'Operations', [
                'admin'   => [true, true],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),
            'day-summary' => self::page('Day Summary', 'Operations', [
                'admin'   => [true, false],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),

            // ── Money ─────────────────────────────────────────────────────
            'dashboard' => self::page('Dashboard', 'Money', [
                'admin'   => [true, false],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),
            'reports' => self::page('Reports', 'Money', [
                'admin'   => [true, false],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),
            'cash-balance' => self::page('Cash Balance', 'Money', [
                'admin'   => [true, true],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),
            'accounts' => self::page('Accounts', 'Money', [
                'admin'   => [true, true],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),
            'payments' => self::page('Payments', 'Money', [
                'admin'   => [true, true],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),
            'expenses' => self::page('Expenses', 'Money', [
                'admin'   => [true, true],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),

            // ── Setup ─────────────────────────────────────────────────────
            'services' => self::page('Services', 'Setup', [
                'admin'   => [true, true],
                'cashier' => [true, false],
                'staff'   => [true, false],
            ]),
            'loyalty' => self::page('Loyalty', 'Setup', [
                'admin'   => [true, true],
                'cashier' => [true, false],
                'staff'   => [true, false],
            ]),
            'machine-cycles' => self::page('Machine Cycles', 'Setup', [
                'admin'   => [true, true],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),
            'users' => self::page('Users', 'Setup', [
                'admin'   => [true, true],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),
            'settings' => self::page('Settings', 'Setup', [
                'admin'   => [true, true],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),

            // ── Super admin only — never configurable per branch ───────────
            'branches'        => self::locked('Branches'),
            'cross-branch'    => self::locked('All Branches'),
            'activity'        => self::locked('Activity'),
            'deleted-records' => self::locked('Deleted Records'),
            'data-management' => self::locked('Data Management'),
            'pickup-queue'    => self::locked('Pickup Queue'),
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function isConfigurable(string $page): bool
    {
        return (bool) (self::all()[$page]['configurable'] ?? false);
    }

    /**
     * The shipped access for a role on a page, used whenever a branch has no
     * override stored.
     */
    public static function default(string $page, string $role): array
    {
        return self::all()[$page]['defaults'][$role] ?? ['view' => false, 'edit' => false];
    }

    private static function page(string $label, string $group, array $defaults): array
    {
        return [
            'label'        => $label,
            'group'        => $group,
            'configurable' => true,
            'defaults'     => self::expand($defaults),
        ];
    }

    private static function locked(string $label): array
    {
        return [
            'label'        => $label,
            'group'        => 'Super Admin',
            'configurable' => false,
            'defaults'     => self::expand([
                'admin'   => [false, false],
                'cashier' => [false, false],
                'staff'   => [false, false],
            ]),
        ];
    }

    private static function expand(array $defaults): array
    {
        $out = [];
        foreach (self::ROLES as $role) {
            [$view, $edit] = $defaults[$role] ?? [false, false];
            $out[$role] = ['view' => $view, 'edit' => $edit];
        }

        return $out;
    }
}
