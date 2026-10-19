<?php

namespace App\Services\Zoho;

/**
 * The raw Zoho Analytics *Tables* (Zoho's own synced copy of Books/Inventory
 * data, not anyone's Query Table) that Dr. Ahmed's 12 queries read — see
 * database/zoho-analytics-queries/. Manager directive 2026-10-19: we pull
 * only these raw tables and run his queries ourselves, so an edit made to a
 * Query Table inside Zoho Analytics can never silently change our numbers.
 *
 * Each table is copied 1:1 into zoho_raw_<key>: every column of the source
 * view, named by normalizeHeader() ("Transaction Amount (BCY)" →
 * transaction_amount_bcy), typed from the view's own column metadata.
 *
 * mode:
 *   full        — reloaded completely every run (staging table + atomic swap).
 *   incremental — every run upserts only rows whose "Last Modified Time" is
 *                 at/after the stored watermark (minus OVERLAP_MINUTES);
 *                 a full reload (staging + swap) runs weekly so rows deleted
 *                 in Zoho are dropped here too. Requires a unique 'key'.
 *
 * 2026-10-19: every table is incremental (hourly refresh, 'zoho:refresh'). The 11 former full-reload tables
 * all carry "Last Modified Time" and a unique id (checked live: no duplicates, no NULLs); reloading their
 * ~326k rows every hour would cost ~1,000 API units per run.
 *
 * Like the Lovable portal (hourly incremental + a weekly full reload), every table is also reloaded in full once a
 * week, so rows deleted in Zoho drop out here — an incremental pull never sees a deletion. The ~6.6M rows are split
 * over the weekend (WEEKLY_FULL, user request 2026-10-19), each night right after the daily API-units reset: Friday
 * (no hourly refresh that day) and Saturday, done before the working day starts.
 */
class ZohoRawTables
{
    public const STATE_TABLE = 'zoho_raw_sync_state';

    /** Weekly full reload (staging + swap): scheduler day of week (5 = Friday, 6 = Saturday) => table keys. */
    public const WEEKLY_FULL = [
              5 => ['accrual_transactions', 'customers', 'accounts', 'items', 'invoices', 'invoice_payments', 'customer_payments',
            'credit_notes', 'creditnotes_invoice', 'credit_note_items', 'sales_orders', 'batch_number_in',
            'locations', 'sales_order_invoice', 'price_lists'], // ~2.7M rows~3.9M
    ];

    public const OVERLAP_MINUTES = 180;

    public static function all(): array
    {
        return [
            'customers' => ['view_id' => '100722000000376157', 'name' => 'Customers', 'mode' => 'incremental', 'key' => ['customer_id']],
            'accounts' => ['view_id' => '100722000000376159', 'name' => 'Accounts', 'mode' => 'incremental', 'key' => ['account_id']],
            'items' => ['view_id' => '100722000000376158', 'name' => 'Items', 'mode' => 'incremental', 'key' => ['item_id']],
            'invoices' => ['view_id' => '100722000000376160', 'name' => 'Invoices', 'mode' => 'incremental', 'key' => ['invoice_id']],
            'invoice_payments' => ['view_id' => '100722000000376162', 'name' => 'Invoice Payments', 'mode' => 'incremental', 'key' => ['invoice_payment_id']],
            'customer_payments' => ['view_id' => '100722000000376163', 'name' => 'Customer Payments', 'mode' => 'incremental', 'key' => ['payment_id']],
            'credit_notes' => ['view_id' => '100722000001549062', 'name' => 'Credit Notes', 'mode' => 'incremental', 'key' => ['creditnotes_id']],
            'creditnotes_invoice' => ['view_id' => '100722000001549152', 'name' => 'Creditnotes Invoice', 'mode' => 'incremental', 'key' => ['creditnotes_invoice_id']],
            'credit_note_items' => ['view_id' => '100722000001549286', 'name' => 'Credit Note Items', 'mode' => 'incremental', 'key' => ['item_id']],
            'sales_orders' => ['view_id' => '100722000003538254', 'name' => 'Sales Orders', 'mode' => 'incremental', 'key' => ['sales_order_id']],
            'batch_number_in' => ['view_id' => '100722000000376341', 'name' => 'Batch Number IN', 'mode' => 'incremental', 'key' => ['batch_in_id']],

            'accrual_transactions' => ['view_id' => '100722000001468301', 'name' => 'Accrual Transactions', 'mode' => 'incremental', 'key' => ['accrual_transaction_id']],
            'invoice_items' => ['view_id' => '100722000000376161', 'name' => 'Invoice Items', 'mode' => 'incremental', 'key' => ['item_id']],
            'sales_order_items' => ['view_id' => '100722000003538310', 'name' => 'Sales Order Items', 'mode' => 'incremental', 'key' => ['item_id']],
            'stock_out_flow_table' => ['view_id' => '100722000000376339', 'name' => 'Stock Out Flow Table', 'mode' => 'incremental', 'key' => ['stock_out_flow_id']],
            'fifo_mapping_table' => ['view_id' => '100722000000376340', 'name' => 'FIFO Mapping Table', 'mode' => 'incremental', 'key' => ['stock_in_flow_id', 'stock_out_flow_id']],
            'batch_number_out' => ['view_id' => '100722000001474401', 'name' => 'Batch Number Out', 'mode' => 'incremental', 'key' => ['batch_out_id']],
             'locations' => ['view_id' => '100722000001322031', 'name' => 'Locations', 'mode' => 'incremental', 'key' => ['location_id']],
            'sales_order_invoice' => ['view_id' => '100722000003538381', 'name' => 'Sales Order Invoice', 'mode' => 'incremental', 'key' => ['invoice_id', 'sales_order_id']],
            'price_lists' => ['view_id' => '100722000001580879', 'name' => 'Price lists', 'mode' => 'incremental', 'key' => ['pricelist_id']],
        ];
    }

    /** Join/filter columns the queries use — indexed after every load. */
    public const INDEXES = [
        'customers' => [['customer_number']],
        'invoices' => [['customer_id']],
        'invoice_payments' => [['invoice_id'], ['payment_id']],
        'creditnotes_invoice' => [['invoice_id']],
        'credit_notes' => [['customer_id']],
        'credit_note_items' => [['creditnotes_id']],
        'sales_orders' => [['customer_id']],
        'batch_number_in' => [['creditnotes_id', 'credit_notes_item_id']],
        'accrual_transactions' => [['entity_id', 'customer_id'], ['customer_id', 'entity_type'], ['transaction_id', 'entity_type'], ['account_id']],
        'invoice_items' => [['invoice_id'], ['product_id']],
        'sales_order_items' => [['sales_order_id']],
        'stock_out_flow_table' => [['invoice_item_id']],
        'fifo_mapping_table' => [['stock_out_flow_id']],
        'batch_number_out' => [['invoice_item_id'], ['batch_in_id']],
        'sales_order_invoice' => [['invoice_id'], ['sales_order_id']],
    ];

    /** Plain-text columns kept as VARCHAR (indexable / compared) even in very wide tables. */
    public const VARCHAR_COLUMNS = [
        'customer_number', 'entity_type', 'debit_or_credit', 'account_type', 'account_name', 'status',
        'invoice_status', 'transaction_number', 'invoice_number', 'credit_note_number', 'sales_order', 'batch_number',
    ];

    public static function get(string $key): array
    {
        $all = self::all();
        if (! isset($all[$key])) {
            throw new \InvalidArgumentException("Unknown raw Zoho table '{$key}'.");
        }

        return $all[$key] + ['key_name' => $key, 'table' => self::table($key)];
    }

    public static function table(string $key): string
    {
        return 'zoho_raw_'.$key;
    }

    /** "Transaction Amount (BCY)" → transaction_amount_bcy, "Sales Order#" → sales_order, "DISC%" → disc_pct. */
    public static function normalizeHeader(string $header): string
    {
        $h = mb_strtolower(trim(preg_replace('/^\x{FEFF}/u', '', $header)));
        $h = str_replace('%', ' pct ', $h);
        $h = preg_replace('/[^a-z0-9]+/', '_', $h);

        return trim($h, '_');
    }

    /**
     * normalizeHeader() over a whole header row, de-duplicated in order — e.g.
     * Accounts has both "Cash Flow - Type" and "Cash Flow Type", so the second
     * becomes cash_flow_type_2. Used for both the view metadata and the CSV
     * header, so the two always agree.
     *
     * @param  list<string>  $headers
     * @return list<string>
     */
    public static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach (array_values($headers) as $header) {
            $name = self::normalizeHeader($header);
            if ($name === '') {
                throw new \RuntimeException("Column '{$header}' normalizes to an empty name.");
            }
            for ($n = 2, $base = $name; in_array($name, $out, true); $n++) {
                $name = "{$base}_{$n}";
            }
            $out[] = $name;
        }

        return $out;
    }

    /**
     * Zoho "Number" columns that the queries compare with '' (blank check).
     * In Zoho, 0 = '' is false; in MySQL a BIGINT 0 = '' is TRUE (the '' is
     * cast to 0) — confirmed live 2026-10-19: customers with CR NO / GLN NO
     * stored as 0 came out Critical here but PASS in Zoho's own
     * Documents_Classification. Stored as text, the comparison behaves like
     * Zoho's without touching Dr. Ahmed's SQL.
     */
    public const NUMBER_AS_TEXT = ['customers' => ['cr_no', 'gln_no']];

    /** Zoho Analytics column dataTypeName → MySQL column type. */
    public static function mysqlType(string $column, string $zohoType, ?string $table = null): string
    {
        if ($table !== null && in_array($column, self::NUMBER_AS_TEXT[$table] ?? [], true)) {
            return 'VARCHAR(500) NULL';
        }

        return match ($zohoType) {
            'Number', 'Auto Number' => 'BIGINT NULL',
            'Currency', 'Decimal Number', 'Percentage' => 'DECIMAL(24,6) NULL',
            'Date', 'Date Time' => 'DATETIME NULL',
            'Yes/No Decision', 'Boolean' => 'TINYINT(1) NULL',
            // Free text can be long (Items' scientific_name overflowed 191) — only the short join/filter columns are VARCHAR.
            default => in_array($column, self::VARCHAR_COLUMNS, true) ? 'VARCHAR(500) NULL' : 'TEXT NULL',
        };
    }
}
