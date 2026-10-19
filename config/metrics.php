<?php

// The metric catalog: the fixed vocabulary of what can be computed across
// four subjects — inventory (stock snapshot), sales, purchase_orders, and
// returns. Used by the dashboard AND the chat Q&A / dynamic-stat features —
// one query engine, one set of allowed questions, not two.
//
// Every metric declares a "subject" saying which table it queries. A
// dimension/filter also declares which subject(s) it's valid for — a
// single subject string, an array of subjects, or 'any' — and
// MetricCatalog rejects combining a metric with a dimension/filter outside
// that (e.g. grouping a sales metric by "category", which sale_items
// doesn't have) rather than letting it reach the database as a broken
// query.
//
// A "metric" is either an aggregate over all matching rows (sum/count/avg),
// or a "listing" that returns individual rows sorted by a column (used for
// things like "lowest stock items"). Nothing here executes raw SQL from
// user or AI input — this file is the only place new metrics get added.

return [

    'metrics' => [

        // --- Inventory (stock snapshot) ---
        'total_stock' => [
            'label' => 'Total Stock (units)',
            'type' => 'aggregate',
            'subject' => 'inventory',
            'aggregation' => 'sum',
            'column' => 'stock',
        ],
        'total_stock_value' => [
            'label' => 'Total Stock Value',
            'type' => 'aggregate',
            'subject' => 'inventory',
            'aggregation' => 'sum_expression',
            'expression' => 'stock * price',
        ],
        'item_count' => [
            'label' => 'Item Count',
            'type' => 'aggregate',
            'subject' => 'inventory',
            'aggregation' => 'count',
        ],
        'average_price' => [
            'label' => 'Average Price',
            'type' => 'aggregate',
            'subject' => 'inventory',
            'aggregation' => 'avg',
            'column' => 'price',
        ],
        'stockout_count' => [
            'label' => 'Stockout Count',
            'type' => 'aggregate',
            'subject' => 'inventory',
            'aggregation' => 'count',
            'implicit_filters' => ['stock_eq' => 0],
        ],
        'lowest_stock_items' => [
            'label' => 'Lowest Stock Items',
            'type' => 'listing',
            'subject' => 'inventory',
            'sort_column' => 'stock',
            'sort_direction' => 'asc',
            'exclude_zero_stock' => true,
        ],
        'highest_value_items' => [
            'label' => 'Highest Value Items',
            'type' => 'listing',
            'subject' => 'inventory',
            'sort_expression' => 'stock * price',
            'sort_direction' => 'desc',
        ],

        // --- Sales (order history) ---
        'total_quantity_ordered' => [
            'label' => 'Total Units Ordered',
            'type' => 'aggregate',
            'subject' => 'sales',
            'aggregation' => 'sum',
            'column' => 'quantity',
            'implicit_filters' => ['status_ne' => 'cancelled'],
        ],
        'cancelled_orders_count' => [
            'label' => 'Cancelled Orders',
            'type' => 'aggregate',
            'subject' => 'sales',
            'aggregation' => 'count',
            'implicit_filters' => ['status_eq' => 'cancelled'],
        ],
        'most_ordered_products' => [
            'label' => 'Units Ordered',
            'type' => 'aggregate',
            'subject' => 'sales',
            'aggregation' => 'sum',
            'column' => 'quantity',
            'implicit_filters' => ['status_ne' => 'cancelled'],
            'default_group_by' => 'sku',
            'default_top_n' => 10,
        ],
        'most_cancelled_products' => [
            'label' => 'Cancelled Orders',
            'type' => 'aggregate',
            'subject' => 'sales',
            'aggregation' => 'count',
            'implicit_filters' => ['status_eq' => 'cancelled'],
            'default_group_by' => 'sku',
            'default_top_n' => 10,
        ],
        'orders_by_month' => [
            'label' => 'Orders',
            'type' => 'aggregate',
            'subject' => 'sales',
            'aggregation' => 'count',
            'implicit_filters' => ['status_ne' => 'cancelled'],
            'default_group_by' => 'order_month',
        ],

        // --- Purchase orders (buying from suppliers) ---
        'total_quantity_purchased' => [
            'label' => 'Total Units Purchased',
            'type' => 'aggregate',
            'subject' => 'purchase_orders',
            'aggregation' => 'sum',
            'column' => 'quantity',
            'implicit_filters' => ['status_ne' => 'cancelled'],
        ],
        'pending_purchase_orders_count' => [
            'label' => 'Pending Purchase Orders',
            'type' => 'aggregate',
            'subject' => 'purchase_orders',
            'aggregation' => 'count',
            'implicit_filters' => ['status_eq' => 'ordered'],
        ],
        'most_purchased_products' => [
            'label' => 'Units Purchased',
            'type' => 'aggregate',
            'subject' => 'purchase_orders',
            'aggregation' => 'sum',
            'column' => 'quantity',
            'implicit_filters' => ['status_ne' => 'cancelled'],
            'default_group_by' => 'sku',
            'default_top_n' => 10,
        ],
        'purchases_by_month' => [
            'label' => 'Purchase Orders',
            'type' => 'aggregate',
            'subject' => 'purchase_orders',
            'aggregation' => 'count',
            'implicit_filters' => ['status_ne' => 'cancelled'],
            'default_group_by' => 'transaction_month',
        ],

        // --- Returns ---
        'total_units_returned' => [
            'label' => 'Total Units Returned',
            'type' => 'aggregate',
            'subject' => 'returns',
            'aggregation' => 'sum',
            'column' => 'quantity',
        ],
        'most_returned_products' => [
            'label' => 'Units Returned',
            'type' => 'aggregate',
            'subject' => 'returns',
            'aggregation' => 'sum',
            'column' => 'quantity',
            'default_group_by' => 'sku',
            'default_top_n' => 10,
        ],
        'returns_by_month' => [
            'label' => 'Returns',
            'type' => 'aggregate',
            'subject' => 'returns',
            'aggregation' => 'count',
            'default_group_by' => 'transaction_month',
        ],
        'returns_by_reason' => [
            'label' => 'Returns',
            'type' => 'aggregate',
            'subject' => 'returns',
            'aggregation' => 'count',
            'default_group_by' => 'reason',
        ],

        // --- Customers (AR ledger / credit usage — live company-wide
        // tables fed by a scheduled sync, not a per-user import the way
        // inventory/sales/purchase_orders/returns are; see
        // project_customer360_tenant_gap_pre_sale memory for why these
        // aren't (yet) scoped the same way, and StatsQueryBuilder's
        // IMPORT_SCOPED_SUBJECTS for how that's handled here) ---
        'customer_outstanding_balance' => [
            'label' => 'Outstanding Balance',
            'type' => 'aggregate',
            'subject' => 'customer_ledger',
            'aggregation' => 'sum',
            'column' => 'statement_amount',
        ],
        'customer_net_sales' => [
            'label' => 'Net Sales (Lifetime)',
            'type' => 'aggregate',
            'subject' => 'customer_ledger',
            'aggregation' => 'sum',
            'column' => 'statement_amount',
            // category=SALES covers both invoice (positive) and creditnote
            // (already negative) rows — summing both nets them correctly,
            // same convention as CustomerController's own Sales tab.
            'implicit_filters' => ['category_eq' => 'SALES'],
        ],
        'customer_collection' => [
            'label' => 'Collection (Lifetime)',
            'type' => 'aggregate',
            'subject' => 'customer_ledger',
            'aggregation' => 'sum_expression',
            'expression' => '-statement_amount',
            'implicit_filters' => ['category_eq' => 'COLLECTION'],
        ],
        'customer_credit_limit' => [
            'label' => 'Credit Limit',
            'type' => 'aggregate',
            'subject' => 'customer_credit',
            // 'avg' rather than 'sum': one row per customer, so avg over a
            // single filtered customer is just their value, and avg across
            // all customers (no filter) is a sensible company-wide figure —
            // sum would double-count nothing but reads oddly for a limit.
            'aggregation' => 'avg',
            'column' => 'credit_limit',
        ],
        'customer_used_credit' => [
            'label' => 'Used Credit',
            'type' => 'aggregate',
            'subject' => 'customer_credit',
            'aggregation' => 'avg',
            'column' => 'used_credit',
        ],
        'customer_remaining_credit' => [
            'label' => 'Remaining Credit',
            'type' => 'aggregate',
            'subject' => 'customer_credit',
            'aggregation' => 'avg',
            'column' => 'remaining_credit',
        ],
    ],

    // Columns/expressions a metric can be grouped by (for breakdown charts).
    // 'subject' may be a single subject, an array of subjects, or 'any'.
    'dimensions' => [
        // null_label: rows where this column is null get bucketed under this
        // label instead of being silently dropped from the breakdown. Zoho
        // items commonly arrive with no category/location set, and
        // previously the entire breakdown would just say "no data" — this
        // way at least the total still shows up, under an honest label.
        'category' => ['label' => 'Category', 'subject' => 'inventory', 'column' => 'category', 'null_label' => 'Uncategorized'],
        'warehouse' => ['label' => 'Warehouse', 'subject' => 'inventory', 'column' => 'warehouse', 'null_label' => 'No Warehouse Set'],
        'sku' => ['label' => 'SKU', 'subject' => 'any', 'column' => 'sku'],
        'order_month' => [
            'label' => 'Month',
            'subject' => 'sales',
            'expression' => "DATE_FORMAT(order_date, '%Y-%m')",
            'sort' => 'label_asc',
        ],
        'transaction_month' => [
            'label' => 'Month',
            'subject' => ['purchase_orders', 'returns'],
            'expression' => "DATE_FORMAT(transaction_date, '%Y-%m')",
            'sort' => 'label_asc',
        ],
        'reason' => ['label' => 'Reason', 'subject' => 'returns', 'column' => 'status'],
    ],

    // Columns a metric can be filtered by. 'any' means valid for every subject.
    'filters' => [
        'category' => ['subject' => 'inventory', 'column' => 'category'],
        'warehouse' => ['subject' => 'inventory', 'column' => 'warehouse'],
        'sku' => ['subject' => 'any', 'column' => 'sku'],
        'status' => ['subject' => ['sales', 'purchase_orders'], 'column' => 'status'],
        // Exact match — use when the question names a customer number
        // (e.g. "CUS-263") verbatim.
        'customer_number' => ['subject' => ['customer_ledger', 'customer_credit'], 'column' => 'customer_number'],
        // 'contains' (LIKE %value%) rather than exact — a question naming a
        // company ("شركة مداوي") won't match customer_name's full stored
        // string exactly, and the LLM has no way to know that exact string.
        'customer_name' => ['subject' => ['customer_ledger', 'customer_credit'], 'column' => 'customer_name', 'match' => 'contains'],
    ],

];
