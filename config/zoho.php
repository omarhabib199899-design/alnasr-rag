<?php

return [
    'client_id' => env('ZOHO_CLIENT_ID'),
    'client_secret' => env('ZOHO_CLIENT_SECRET'),
    'accounts_base_url' => env('ZOHO_ACCOUNTS_BASE_URL', 'https://accounts.zoho.com'),

    /**
     * Zoho Analytics workspace this org's reporting tables live in ("Zoho
     * Books Analytics", shared with this account — not owned by it, hence
     * a fixed workspace/org id rather than something discoverable per
     * connection). orgId here is the Analytics-side org id, unrelated to
     * the Inventory/Books zoho_organization_id stored on ZohoConnection.
     *
     * views: table-name => Zoho Analytics viewId (QueryTable), discovered
     * once via GET /restapi/v2/workspaces/{workspaceId}/views (303 views
     * total) on 2026-09-23. sales_item_performance confirmed live against
     * the manual CSV export (766,521 data rows, exact column match); the
     * rest matched by view name against each importer's documented source
     * filename, not yet all individually confirmed live.
     */
    'analytics' => [
        'workspace_id' => '100722000000376064',
        'org_id' => '150000064185',
        'views' => [
            'sales_item_performance' => '100722000003888393',
            'customer_sales_recommendations' => '100722000004273145', // "Final Sales Decision Engine"
            'customer_credit_usage' => '100722000004326459', // "CREDIT LIMIT USAGE"
            'customer_sales_behavior' => '100722000004250708', // "Customer Sales Behavior Classification"
            'customer_document_insights' => '100722000003946014', // "Customer Document Insights"
            'sales_item_monthly_performance' => '100722000004034484', // "Sales Item Monthly Performance"
            'ar_ledger' => '100722000003849069', // "AR Ledger Master"
            'customer_contact_persons' => '100722000000376361', // "Customer Contact Persons"
            'invoice_documents' => '100722000003555784', // "INVOICE OPERATIONS NDC" — medium-high confidence match, verify row/column shape on first live run

            // Customer classification Query Tables — built 2026-09-26 from
            // Dr. Ahmed's exact SQL (see project docs), created directly in
            // this workspace rather than reusing any pre-existing view, so
            // the output is guaranteed to match his formulas exactly. Each
            // "_staging" table feeds its paired final classification table
            // via a literal FROM reference (not "TEST", which Dr. Ahmed
            // reuses as a scratch table for unrelated ad-hoc work) — do not
            // rename these tables in Analytics without updating both sides.
            'sales_classification_staging' => '100722000005134214',
            'sales_classification' => '100722000005134285',
            'collection_classification_staging' => '100722000005134302',
            'collection_classification' => '100722000005134335',
            'profitability_staging' => '100722000005134353',
            'profitability_classification' => '100722000005134370',
            'documents_classification' => '100722000005134399',

            // "INVENTORY DATA" — batch/FIFO-level stock+valuation report
            // (7,675 rows for 6,408 items as of 2026-10-14), already built
            // and maintained in this workspace for aging/expiry reporting.
            // Confirmed live 2026-10-14 its item count matches Zoho's own
            // live Items UI exactly (6,408) — used by SyncZohoInventoryJob
            // to source stock-on-hand via one bulk export instead of one
            // REST detail call per item (the latter made a full inventory
            // refresh take 17-30+ hours, starved against the same shared
            // 500/hr throttle every transaction sync also depends on).
            'inventory_stock' => '100722000003592046',

            // Credit Note line-item detail — confirmed live 2026-10-15,
            // used together: "Credit Note Items" has the Quantity/Total/SKU-
            // level rows but only an internal CreditNotes ID (no human
            // number); "Credit Notes" has that number (e.g. "CN-06310",
            // matching ar_ledger_entries.transaction_number exactly) keyed
            // by the same CreditNotes ID. See SyncCreditNoteItems.
            'credit_note_items' => '100722000001549286',
            'credit_notes' => '100722000001549062',

            // "Customers" Table (83 columns, 1,498 rows) — the same
            // workspace's own live Zoho-synced mirror of Contacts, discovered
            // 2026-10-17 while comparing against the Al-Nasr Lovable portal
            // (which reads this exact view). Used by SyncCustomerAssignments
            // for rep_name/sales_owner/data_entry only — see that command's
            // docblock for why those 3 fields specifically, not the whole
            // customer record, come from here instead of the live Contacts
            // API: confirmed live the same day the live API's page-shift
            // skip bug (same class as SyncZohoTransactionTypeJob's, just not
            // yet hit for these fields at the time) had silently left 74
            // customers' Data Entry assignment stale, while this view's
            // numbers matched Zoho exactly at check time.
            'customers_directory' => '100722000000376157',
        ],
    ],
];
