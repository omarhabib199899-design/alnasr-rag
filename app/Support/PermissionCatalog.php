<?php

namespace App\Support;

/**
 * The full list of permission keys a Role can be granted, grouped for the
 * role-creation checkboxes. Per an explicit ask to keep this simple for now
 * (no per-field/per-action permissions, just "can this role see this nav
 * section / this customer tab") — checked by Auth::user()->can() helpers
 * added on the User model, and used to hide (not hard-block) nav items and
 * customer tabs. A user with no role (role_id null) is unrestricted.
 */
class PermissionCatalog
{
    public const NAV_SECTIONS = [
        'nav.dashboard' => 'Dashboard',
        'nav.imports' => 'Imports',
        'nav.customers' => 'Customers',
        'nav.reports' => 'Reports',
        'nav.chat' => 'Chat',
        'nav.configuration' => 'Configuration',
        'nav.users' => 'User Management',
        'nav.activity-log' => 'Activity Log',
    ];

    public const CUSTOMER_TABS = [
        'customers.tab.overview' => 'Overview',
        'customers.tab.general' => 'General',
        'customers.tab.sales' => 'Sales',
        'customers.tab.return' => 'Return',
        'customers.tab.sales_orders' => 'Sales Orders',
        'customers.tab.profitability' => 'Profitability',
        'customers.tab.collection' => 'Collection',
        'customers.tab.ar' => 'Accounts Receivable',
        'customers.tab.due_ages' => 'Due Ages',
        'customers.tab.credit_limit' => 'Credit Limit',
        'customers.tab.item_performance' => 'Item Performance',
        'customers.tab.insights' => 'Insights',
        'customers.tab.sales_history' => 'Sales History',
        'customers.tab.history' => 'Change History',
    ];

    /**
     * @return array<string, array<string, string>> group label => [key => label]
     */
    public static function grouped(): array
    {
        return [
            'Navigation Sections' => self::NAV_SECTIONS,
            'Customer Tabs' => self::CUSTOMER_TABS,
        ];
    }
}
