<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Fills the older tables that existing pages still read (Customers list, Credit Notes, Elnasr Recommendations, AI
 * chat, the /legacy pages) from our own computed copies of the same Zoho Analytics Query Tables — no Zoho API call:
 *
 *   ar_ledger_entries      <- zoho_ar_sales               (was reports:sync-ar-ledger, ~66k rows every hour)
 *   sales_item_performance <- zoho_sales_item_performance (was reports:sync-sales-item-performance, ~800k rows daily)
 *   purchase_order_items   <- zoho_raw_purchase_orders + _purchase_order_items + _purchase_receive_items + _vendors + _items
 *                             (was the Inventory API purchase-order sync; Purchase Orders report, Dashboard, AI chat and
 *                             Purchase Recommendations read it)
 *   sale_items             <- zoho_raw_sales_orders + _sales_order_items   (was the Inventory/Books sales-order sync)
 *   invoice_items          <- zoho_raw_invoices + _invoice_items           (was the Inventory/Books invoice sync)
 *   return_items           <- zoho_raw_credit_notes + _credit_note_items   (was the Inventory sales-return sync, which had
 *                             only ever stored one row — returns came from an old Excel export; every Zoho return is a
 *                             credit note)
 *
 * The three keep the values the pages filter on (checked 2026-10-19 against the API-synced rows, matched by document /
 * line id: invoices 726,708 of 727,224 lines): invoice status Closed/Open/Overdue/PartiallyPaid/Draft becomes
 * paid/sent/overdue/partially_paid/draft as the API wrote it; a sales order is draft / void (void, rejected) or
 * confirmed (Analytics calls an order "invoiced" once billed; the API kept "confirmed") — Analytics' own status is kept in
 * extra.zoho_status. Rows come only from Analytics, so the old Excel-export copies (no zoho_document_id, ~776k sales
 * and ~629k invoice lines that duplicated the synced ones) and lines of orders since deleted in Zoho drop out.
 *
 * purchase_order_items.status is the RECEIVE status the pages expect (issued / partially_received / received /
 * cancelled / draft) — Analytics' Purchase Order Status is the billing status, so it is rebuilt from the received
 * quantities in Purchase Receive Items (checked 2026-10-19 against the Inventory API statuses: 4,604 of 5,114 orders
 * identical). 502 orders the Inventory API called partially_received have no receive at all in Analytics; for an
 * order with nothing received, the status saved from the Inventory API (purchase_order_status_snapshot, taken once
 * before the first feed) is kept when it was partially_received / received. Rows come only from Analytics, so the old
 * Excel-import copies (zoho_export, duplicates of synced orders) drop out. Discount is a text column in Zoho
 * ("16ز66" was typed on an Arabic keyboard, where ز is the "." key): it is cleaned, and anything that is still not a
 * plain 0–999 number is left empty instead of failing the whole feed.
 *
 * Both sources were verified identical to Zoho's own output (AR SALES 69,994/69,994 rows; SIP 806,131 rows), so the
 * pages keep their exact numbers while the two old full exports (~7,000 API units a day) stop. Runs at the end of
 * every zoho:refresh, right after the queries are recomputed.
 *
 * customers (our own list, which Customer 360 links, Elnasr Recommendations and the /legacy pages read) is kept in step
 * with zoho_raw_customers here too, replacing the Books Contacts sync and reports:reconcile-customers: new customers are
 * added, name / number / status / account type / assignments are refreshed, and a customer no longer in Zoho gets
 * zoho_missing_since (cleared again if it comes back). The Contacts-only details (email, phone, address in
 * live_contact_data) stay as last synced.
 *
 * Same safety as the old imports: build a staging copy, refuse it if it is implausibly small next to the live table,
 * then swap atomically — the live table is never emptied first.
 */
class FeedLegacyTablesFromZohoQueries extends Command
{
    protected $signature = 'zoho:feed-legacy-tables';

    protected $description = 'Refresh the older pages\' tables (AR ledger, item performance, purchase/sales orders, invoices, returns) from the Zoho Analytics tables (no API call)';

    /** A rebuild smaller than this share of the current live row count is refused. */
    private const MIN_RATIO = 0.9;

    private const FEEDS = [
        'ar_ledger_entries' => <<<'SQL'
            INSERT INTO `%s` (transaction_date, transaction_number, entity_type, category, zoho_entity_id, zoho_transaction_id,
                zoho_customer_id, customer_number, customer_name, ar_account_name, account_type, reference_no, debit_or_credit,
                statement_amount, balance, inv_balance_due_date, sales_exc_vat, cogs, gross_profit, gp_percent, kpi_amount,
                balance_aging, created_at, updated_at)
            SELECT DATE(transaction_date), transaction_number, entity_type, category, entity_id, transaction_id,
                customer_id, customer_number, customer_name, ar_account_name, account_type, reference_no, debit_or_credit,
                statement_amount, balance, DATE(inv_balance_due_date), sales_exc_vat, cogs, gross_profit, gp_pct, kpi_amount,
                balance_aging, ?, ?
            FROM zoho_ar_sales
            WHERE customer_id IS NOT NULL
            SQL,
        'sales_item_performance' => <<<'SQL'
            INSERT INTO `%s` (zoho_transaction_id, transaction_number, transaction_type, transaction_date, zoho_customer_id,
                zoho_item_id, item_name, zoho_location_id, qty, batch, expiry, item_price, discount_amount, cogs, item_total,
                item_gross_profit, discount_percent, created_at, updated_at)
            SELECT transaction_id, transaction_number, transaction_type, DATE(transaction_date), customer_id,
                item_id, item_name, location_id, qty, batch, DATE_FORMAT(expiry, '%%d-%%m-%%Y'), item_price, discount_amount, cogs, item_total,
                item_gross_profit, discount_pct, ?, ?
            FROM zoho_sales_item_performance
            SQL,
        'purchase_order_items' => <<<'SQL'
            INSERT INTO `%s` (import_id, sku, vendor_name, rate, discount_percent, quantity, transaction_date, status, extra,
                zoho_document_id, zoho_line_item_id, document_number, created_at, updated_at)
            SELECT (SELECT MAX(id) FROM imports WHERE type IN ('purchase_orders', 'purchase_orders_recent') AND source = 'zoho'),
                COALESCE(NULLIF(it.sku, ''), CONCAT('ZOHO-', i.product_id)), COALESCE(NULLIF(v.vendor_name, ''), v.display_name),
                i.item_price_bcy, CASE WHEN REPLACE(TRIM(i.discount), 'ز', '.') REGEXP '^[0-9]{1,3}([.][0-9]+)?$' THEN CAST(REPLACE(TRIM(i.discount), 'ز', '.') AS DECIMAL(5,2)) END, ROUND(COALESCE(i.quantity, 0)), COALESCE(DATE(po.purchase_order_date), DATE(po.`date`)),
                CASE WHEN po.purchase_order_status = 'Cancelled' THEN 'cancelled'
                    WHEN po.purchase_order_status = 'Draft' THEN 'draft'
                    WHEN d.received <= 0 THEN CASE WHEN snap.status IN ('partially_received', 'received') THEN snap.status ELSE 'issued' END
                    WHEN d.received < d.ordered THEN 'partially_received'
                    ELSE 'received' END,
                JSON_OBJECT('item_name', i.item_name, 'item_price', i.item_price_bcy, 'discount_percent', CASE WHEN REPLACE(TRIM(i.discount), 'ز', '.') REGEXP '^[0-9]{1,3}([.][0-9]+)?$' THEN CAST(REPLACE(TRIM(i.discount), 'ز', '.') AS DECIMAL(5,2)) END,
                    'sub_total', i.sub_total_bcy, 'total', i.total_bcy, 'zoho_item_id', CAST(i.product_id AS CHAR),
                    'location_id', CAST(i.location_id AS CHAR), 'delivery_date', DATE(po.delivery_date),
                    'expected_delivery_date', DATE(po.expected_delivery_date), 'attention', po.attention_content,
                    'quantity_billed', i.quantity_billed, 'quantity_cancelled', i.quantity_cancelled,
                    'quantity_received', COALESCE(r.q, 0), 'purchase_order_status', po.purchase_order_status, 'manager', po.manager),
                po.purchase_order_id, i.item_id, po.purchase_order_number, ?, ?
            FROM zoho_raw_purchase_order_items i
            JOIN zoho_raw_purchase_orders po ON po.purchase_order_id = i.purchase_order_id
            LEFT JOIN (SELECT purchase_order_item_id, SUM(quantity_received) q FROM zoho_raw_purchase_receive_items GROUP BY purchase_order_item_id) r
                ON r.purchase_order_item_id = i.item_id
            JOIN (SELECT pi.purchase_order_id, SUM(COALESCE(pi.quantity, 0) - COALESCE(pi.quantity_cancelled, 0)) ordered, SUM(COALESCE(rr.q, 0)) received
                    FROM zoho_raw_purchase_order_items pi
                    LEFT JOIN (SELECT purchase_order_item_id, SUM(quantity_received) q FROM zoho_raw_purchase_receive_items GROUP BY purchase_order_item_id) rr
                        ON rr.purchase_order_item_id = pi.item_id
                    GROUP BY pi.purchase_order_id) d ON d.purchase_order_id = po.purchase_order_id
            LEFT JOIN zoho_raw_vendors v ON v.vendor_id = po.vendor_id
            LEFT JOIN zoho_raw_items it ON it.item_id = i.product_id
            LEFT JOIN purchase_order_status_snapshot snap ON snap.zoho_document_id = po.purchase_order_id
            SQL,
        'sale_items' => <<<'SQL'
            INSERT INTO `%s` (import_id, sku, quantity, order_date, status, extra, zoho_document_id, zoho_line_item_id,
                document_number, customer_name, created_at, updated_at)
            SELECT (SELECT MAX(id) FROM imports WHERE type IN ('sales', 'sales_recent') AND source = 'zoho'),
                COALESCE(NULLIF(it.sku, ''), CONCAT('ZOHO-', i.product_id)), ROUND(COALESCE(i.quantity, 0)), DATE(so.order_date),
                CASE LOWER(TRIM(so.status)) WHEN 'draft' THEN 'draft' WHEN 'void' THEN 'void' WHEN 'rejected' THEN 'void' ELSE 'confirmed' END,
                JSON_OBJECT('zoho_item_id', CAST(i.product_id AS CHAR), 'item_name', i.item_name, 'item_price', i.item_price_bcy,
                    'discount_percent', i.entity_discount_percent, 'discount_amount', i.discount_amount_bcy, 'sub_total', i.sub_total_bcy,
                    'total', i.total_bcy, 'quantity_invoiced', i.quantity_invoiced, 'quantity_cancelled', i.quantity_cancelled,
                    'zoho_status', so.status, 'invoiced_status', so.invoiced_status, 'document_total', so.total_bcy),
                so.sales_order_id, i.item_id, so.sales_order, c.customer_name, ?, ?
            FROM zoho_raw_sales_order_items i
            JOIN zoho_raw_sales_orders so ON so.sales_order_id = i.sales_order_id
            LEFT JOIN zoho_raw_items it ON it.item_id = i.product_id
            LEFT JOIN zoho_raw_customers c ON c.customer_id = so.customer_id
            WHERE i.product_id IS NOT NULL AND so.order_date IS NOT NULL
            SQL,
        'invoice_items' => <<<'SQL'
            INSERT INTO `%s` (import_id, sku, quantity, invoice_date, status, extra, zoho_document_id, zoho_line_item_id,
                document_number, customer_name, warehouse, created_at, updated_at)
            SELECT (SELECT MAX(id) FROM imports WHERE type IN ('invoices', 'invoices_recent') AND source = 'zoho'),
                COALESCE(NULLIF(it.sku, ''), CONCAT('ZOHO-', ii.product_id)), ROUND(COALESCE(ii.quantity, 0)), DATE(inv.invoice_date),
                COALESCE(CASE TRIM(inv.status) WHEN 'Closed' THEN 'paid' WHEN 'Open' THEN 'sent' WHEN 'Overdue' THEN 'overdue'
                    WHEN 'PartiallyPaid' THEN 'partially_paid' WHEN 'Draft' THEN 'draft' WHEN 'Void' THEN 'void'
                    ELSE NULLIF(LOWER(REPLACE(TRIM(inv.status), ' ', '_')), '') END, 'completed'),
                JSON_OBJECT('zoho_item_id', CAST(ii.product_id AS CHAR), 'item_name', ii.item_name, 'item_price', ii.item_price,
                    'discount_percent', ii.disc_pct, 'discount_amount', ii.discount_amount_bcy, 'sub_total', ii.sub_total_bcy,
                    'total', ii.total_bcy, 'location_id', CAST(ii.location_id AS CHAR), 'due_date', DATE(inv.due_date),
                    'balance', inv.balance_bcy, 'document_total', inv.total_bcy, 'zoho_status', inv.status),
                inv.invoice_id, ii.item_id, inv.invoice_number, c.customer_name, COALESCE(NULLIF(l.location_name, ''), NULLIF(ii.wh, '')), ?, ?
            FROM zoho_raw_invoice_items ii
            JOIN zoho_raw_invoices inv ON inv.invoice_id = ii.invoice_id
            LEFT JOIN zoho_raw_items it ON it.item_id = ii.product_id
            LEFT JOIN zoho_raw_customers c ON c.customer_id = inv.customer_id
            LEFT JOIN zoho_raw_locations l ON l.location_id = ii.location_id
            WHERE ii.product_id IS NOT NULL AND inv.invoice_date IS NOT NULL
            SQL,
        'return_items' => <<<'SQL'
            INSERT INTO `%s` (import_id, sku, quantity, transaction_date, status, extra, zoho_document_id, zoho_line_item_id,
                document_number, customer_name, created_at, updated_at)
            SELECT (SELECT MAX(id) FROM imports WHERE type IN ('returns', 'returns_recent') AND source = 'zoho'),
                COALESCE(NULLIF(it.sku, ''), CONCAT('ZOHO-', ci.product_id)), ROUND(COALESCE(ci.quantity, 0)), DATE(cn.credit_note_date),
                'processed',
                JSON_OBJECT('zoho_item_id', CAST(ci.product_id AS CHAR), 'item_name', ci.item_name, 'item_price', ci.item_price,
                    'discount_amount', ci.discount_amount_bcy, 'sub_total', ci.sub_total, 'total', ci.total, 'return_value', ci.return_value,
                    'location_id', CAST(ci.location_id AS CHAR), 'city', ci.city, 'credit_note_status', cn.credit_note_status),
                cn.creditnotes_id, ci.item_id, cn.credit_note_number, c.customer_name, ?, ?
            FROM zoho_raw_credit_note_items ci
            JOIN zoho_raw_credit_notes cn ON cn.creditnotes_id = ci.creditnotes_id
            LEFT JOIN zoho_raw_items it ON it.item_id = ci.product_id
            LEFT JOIN zoho_raw_customers c ON c.customer_id = cn.customer_id
            WHERE ci.product_id IS NOT NULL AND cn.credit_note_date IS NOT NULL
            SQL,
    ];

    /** Raw tables a feed needs — skipped (not failed) until their first full load. */
    private const REQUIRES = [
        'purchase_order_items' => ['zoho_raw_purchase_orders', 'zoho_raw_purchase_order_items', 'zoho_raw_purchase_receive_items',
            'zoho_raw_vendors', 'zoho_raw_items'],
        'sale_items' => ['zoho_raw_sales_orders', 'zoho_raw_sales_order_items', 'zoho_raw_items', 'zoho_raw_customers'],
        'invoice_items' => ['zoho_raw_invoices', 'zoho_raw_invoice_items', 'zoho_raw_items', 'zoho_raw_customers', 'zoho_raw_locations'],
        'return_items' => ['zoho_raw_credit_notes', 'zoho_raw_credit_note_items', 'zoho_raw_items', 'zoho_raw_customers'],
    ];

    /** Line tables compared with their API-synced rows only (zoho_document_id set) — the Excel copies are meant to drop out. */
    private const SYNCED_ONLY = ['purchase_order_items', 'sale_items', 'invoice_items', 'return_items'];

    /**
     * sale_items: ~9% of the API-synced lines belong to orders since edited or deleted in Zoho (the API sync only ever
     * added), so the rebuild is legitimately smaller than the live table there.
     */
    private const MIN_RATIO_FOR = ['sale_items' => 0.85];

    public function handle(): int
    {
        $failed = 0;
        try {
            $this->syncCustomers();
        } catch (Throwable $e) {
            $failed++;
            $this->error('customers: FAILED — '.mb_substr($e->getMessage(), 0, 300));
            report($e);
        }
        foreach (self::FEEDS as $table => $sql) {
            $missing = array_filter(self::REQUIRES[$table] ?? [], fn ($t) => ! Schema::hasTable($t));
            if ($missing) {
                $this->warn("{$table}: skipped — not loaded yet: ".implode(', ', $missing));

                continue;
            }
            try {
                if ($table === 'purchase_order_items') {
                    $this->snapshotPurchaseOrderStatuses();
                }
                $this->feed($table, $sql);
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$table}: FAILED — ".mb_substr($e->getMessage(), 0, 300).' (live table left untouched)');
                report($e);
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function feed(string $table, string $sql): void
    {
        $started = microtime(true);
        $staging = $table.'_staging_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
        DB::statement("CREATE TABLE `{$staging}` LIKE `{$table}`");

        try {
            $now = now();
            DB::statement(sprintf($sql, $staging), [$now, $now]);

            $rows = DB::table($staging)->count();
            $live = in_array($table, self::SYNCED_ONLY, true)
                ? DB::table($table)->whereNotNull('zoho_document_id')->count()
                : DB::table($table)->count();
            if ($rows === 0 || $rows < $live * (self::MIN_RATIO_FOR[$table] ?? self::MIN_RATIO)) {
                throw new \RuntimeException("rebuilt {$rows} rows vs {$live} live — refusing to replace the live table.");
            }

            $old = $table.'_old_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
            DB::statement("RENAME TABLE `{$table}` TO `{$old}`, `{$staging}` TO `{$table}`");
            DB::statement("DROP TABLE `{$old}`");
        } catch (Throwable $e) {
            DB::statement("DROP TABLE IF EXISTS `{$staging}`");

            throw $e;
        }

        $this->info(sprintf('%s: %d rows (was %d) in %.1fs.', $table, $rows, $live, microtime(true) - $started));
    }

    private function syncCustomers(): void
    {
        if (! Schema::hasTable('zoho_raw_customers') || DB::table('zoho_raw_customers')->count() < 1000) {
            $this->warn('customers: skipped — zoho_raw_customers not loaded (or implausibly small).');

            return;
        }
        $now = now();
        $added = DB::affectingStatement("INSERT INTO customers (zoho_contact_id, customer_name, display_name, customer_number, status,
                rep_name, sales_owner, data_entry, account_type, created_at, updated_at)
            SELECT CAST(c.customer_id AS CHAR), COALESCE(NULLIF(c.customer_name, ''), c.display_name, CAST(c.customer_id AS CHAR)), c.display_name,
                c.customer_number, c.status, c.rep_name, c.sales_owner, c.data_entry, c.account_type, ?, ?
            FROM zoho_raw_customers c
            WHERE NOT EXISTS (SELECT 1 FROM customers x WHERE x.zoho_contact_id = c.customer_id)", [$now, $now]);
        $updated = DB::affectingStatement("UPDATE customers x JOIN zoho_raw_customers c ON x.zoho_contact_id = c.customer_id
            SET x.customer_name = COALESCE(NULLIF(c.customer_name, ''), x.customer_name), x.display_name = c.display_name,
                x.customer_number = c.customer_number, x.status = c.status, x.rep_name = c.rep_name, x.sales_owner = c.sales_owner,
                x.data_entry = c.data_entry, x.account_type = c.account_type, x.zoho_missing_since = NULL, x.updated_at = ?", [$now]);
        $missing = DB::affectingStatement("UPDATE customers x SET x.zoho_missing_since = ?
            WHERE x.zoho_missing_since IS NULL AND x.zoho_contact_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM zoho_raw_customers c WHERE c.customer_id = x.zoho_contact_id)", [$now]);
        $this->info("customers: {$added} added, {$updated} refreshed, {$missing} no longer in Zoho.");
    }

    /** One-time copy of each order's Inventory-API status, taken while purchase_order_items still holds it. */
    private function snapshotPurchaseOrderStatuses(): void
    {
        if (Schema::hasTable('purchase_order_status_snapshot')) {
            return;
        }
        DB::statement('CREATE TABLE purchase_order_status_snapshot (zoho_document_id VARCHAR(64) NOT NULL PRIMARY KEY, status VARCHAR(64) NULL)');
        DB::statement('INSERT INTO purchase_order_status_snapshot (zoho_document_id, status)
            SELECT zoho_document_id, MIN(status) FROM purchase_order_items WHERE zoho_document_id IS NOT NULL GROUP BY zoho_document_id');
        $this->info('purchase_order_status_snapshot: '.DB::table('purchase_order_status_snapshot')->count().' orders saved.');
    }
}
