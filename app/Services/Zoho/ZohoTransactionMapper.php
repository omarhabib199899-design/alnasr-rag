<?php

namespace App\Services\Zoho;

use App\Services\Inventory\TransactionImportProcessor;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Translates one raw Zoho document (sales order / purchase order / sales
 * return / invoice) into row(s) for sale_items/purchase_order_items/
 * return_items/invoice_items — one row per line item, since a Zoho order
 * can cover multiple SKUs.
 *
 * Reuses TransactionImportProcessor::CONFIG for table/relation/date-column/
 * default-status (single source of truth, shared with the Excel path).
 * Zoho-specific endpoint/field names live only here, not in the Excel class.
 */
class ZohoTransactionMapper
{
    private const ZOHO_CONFIG = [
        'sales' => [
            'endpoint' => 'salesorders',
            'response_key' => 'salesorders',
            // Zoho's list endpoint omits line_items — only the per-document
            // detail endpoint (GET /salesorders/{id}) includes them, wrapped
            // under this singular key. Confirmed against this org's live API
            // for purchaseorders; the other three types follow the same
            // documented Zoho naming convention (singular of the endpoint).
            'detail_response_key' => 'salesorder',
            'date_field' => 'date',
            'status_field' => 'status',
            'id_field' => 'salesorder_id',
            'number_field' => 'salesorder_number',
            'customer_field' => 'customer_name',
        ],
        'purchase_orders' => [
            'endpoint' => 'purchaseorders',
            'response_key' => 'purchaseorders',
            'detail_response_key' => 'purchaseorder',
            'date_field' => 'date',
            'status_field' => 'status',
            'id_field' => 'purchaseorder_id',
            // Purchase orders have a vendor, not a customer — already
            // captured separately as vendor_name below.
            'number_field' => 'purchaseorder_number',
            'customer_field' => null,
        ],
        'returns' => [
            'endpoint' => 'salesreturns',
            'response_key' => 'salesreturns',
            'detail_response_key' => 'salesreturn',
            // Zoho's status field for returns is "salesreturn_status", but
            // return_items.status is used app-wide to mean "reason" (see
            // config/metrics.php's returns_by_reason metric) — so we read
            // "reason" here to match that existing meaning, not the literal
            // status field.
            'date_field' => 'date',
            'status_field' => 'reason',
            'id_field' => 'salesreturn_id',
            // Confirmed against this org's live salesreturn detail response.
            'number_field' => 'salesreturn_number',
            'customer_field' => 'customer_name',
        ],
        'invoices' => [
            'endpoint' => 'invoices',
            'response_key' => 'invoices',
            'detail_response_key' => 'invoice',
            'date_field' => 'date',
            'status_field' => 'status',
            'id_field' => 'invoice_id',
            'number_field' => 'invoice_number',
            'customer_field' => 'customer_name',
        ],
    ];

    public static function zohoConfigFor(string $type): array
    {
        return self::ZOHO_CONFIG[$type] ?? throw new InvalidArgumentException("Unknown Zoho transaction type: {$type}");
    }

    /**
     * Keys mapToRows() writes into every line row's `extra` that describe
     * the DOCUMENT (reference number, addresses, totals, custom fields...),
     * not that specific line item — same value duplicated across every row
     * of one document, same reasoning as the existing document_number/
     * customer_name columns. Exposed as a named list (not just inline in
     * mapToRows()) so ReportsController::document() can pull these out once
     * per document for a single "Document Details" card instead of
     * repeating them under every line item's own "More" toggle.
     */
    public const DOCUMENT_LEVEL_EXTRA_KEYS = [
        'reference_number', 'salesperson_name', 'payment_terms_label', 'currency_code',
        'notes', 'terms', 'billing_address', 'shipping_address',
        'document_sub_total', 'document_discount_total', 'document_tax_total',
        'document_shipping_charge', 'document_adjustment', 'document_total',
        'taxes', 'custom_fields',
        'salesreturn_type', 'refund_status', 'receive_status',
        'delivery_date', 'expected_delivery_date', 'ship_via', 'attention', 'delivery_address',
        'due_date', 'balance', 'payment_made', 'is_emailed',
    ];

    /**
     * "street + street2, city, state, zip, country" with any empty part
     * dropped — Zoho's address objects (billing/shipping/delivery) always
     * carry the full field set even when most of it is blank, so joining
     * blindly would leave a trail of stray ", ,".
     */
    private static function formatAddress(?array $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $parts = array_filter([
            $address['address'] ?? null,
            $address['street2'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['zip'] ?? null,
            $address['country'] ?? null,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        return empty($parts) ? null : implode(', ', $parts);
    }

    /**
     * Zoho's custom_fields array carries ~20 internal bookkeeping keys per
     * entry (field_id, show_in_portal, is_color_code_supported, ...) — only
     * label/value is ever worth showing on a report page, so this strips
     * the rest down to that pair, and drops any field the org hasn't
     * actually filled in for this document.
     */
    private static function simplifyCustomFields(?array $customFields): array
    {
        if (empty($customFields)) {
            return [];
        }

        return collect($customFields)
            ->map(fn ($f) => ['label' => $f['label'] ?? null, 'value' => $f['value_formatted'] ?? $f['value'] ?? null])
            ->filter(fn ($f) => $f['label'] !== null && $f['value'] !== null && $f['value'] !== '')
            ->values()
            ->all();
    }

    /**
     * Zoho's taxes array carries a formatted-string duplicate
     * (tax_amount_formatted) alongside the raw name/amount — only the two
     * meaningful fields are kept.
     */
    private static function simplifyTaxes(?array $taxes): array
    {
        if (empty($taxes)) {
            return [];
        }

        return collect($taxes)
            ->map(fn ($t) => ['name' => $t['tax_name'] ?? null, 'amount' => $t['tax_amount'] ?? null])
            ->filter(fn ($t) => $t['name'] !== null)
            ->values()
            ->all();
    }

    /**
     * Document-level fields for invoice_documents, built from the same
     * detail response mapToRows() already fans out into invoice_items line
     * rows — no extra API call. Field names confirmed live 2026-09-22
     * against a real invoice detail response.
     *
     * Deliberately only covers the fields confirmed against the live API:
     * invoice_status/final_status/age_in_days/age_tier/extra are Analytics-
     * export-only columns (see ImportInvoiceDocumentsAnalyticsReport) and
     * are left untouched here rather than guessed at or overwritten with
     * less complete live data.
     *
     * @param  array<string, mixed>  $document  one raw Zoho invoice detail object
     * @return array<string, mixed>|null null if the document has no invoice_id
     */
    public function mapToInvoiceDocument(array $document): ?array
    {
        $invoiceId = (string) ($document['invoice_id'] ?? '');
        if ($invoiceId === '') {
            return null;
        }

        return [
            'zoho_invoice_id' => $invoiceId,
            'invoice_number' => $document['invoice_number'] ?? null,
            'zoho_customer_id' => isset($document['customer_id']) ? (string) $document['customer_id'] : null,
            'invoice_date' => $this->toDate($document['date'] ?? null),
            'due_date' => $this->toDate($document['due_date'] ?? null),
            'status' => $document['status'] ?? null,
            'sub_total' => $document['sub_total'] ?? null,
            'discount_amount' => $document['discount_total'] ?? null,
            'adjustment' => $document['adjustment'] ?? null,
            'total' => $document['total'] ?? null,
            'balance' => $document['balance'] ?? null,
            'write_off_amount' => $document['write_off_amount'] ?? null,
            'salesperson_id' => $document['salesperson_id'] ?: null,
            'location_id' => isset($document['location_id']) ? (string) $document['location_id'] : null,
            'zoho_created_time' => $this->toDateTime($document['created_time'] ?? null),
            'zoho_last_modified_time' => $this->toDateTime($document['last_modified_time'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $document  one raw Zoho order/return object
     * @param  array<string, string>  $skuByItemId  item_id => sku lookup, built from the items fetch
     * @return array<int, array<string, mixed>> one row per line item
     */
    public function mapToRows(array $document, string $type, int $importId, array $skuByItemId, Carbon $now): array
    {
        $transactionConfig = TransactionImportProcessor::CONFIG[$type];
        $zohoConfig = self::zohoConfigFor($type);
        $dateColumn = $transactionConfig['date_column'];

        $date = $this->toDate($document[$zohoConfig['date_field']] ?? null);
        if ($date === null) {
            return [];
        }

        // Zoho returns the field key with an empty string, not a missing
        // key, when it's unset (confirmed on a live sales return whose
        // "reason" was "") — ?? alone doesn't catch that, so an empty value
        // silently rendered as a blank status badge instead of falling back.
        $rawStatus = $document[$zohoConfig['status_field']] ?? null;
        $status = ($rawStatus === null || $rawStatus === '') ? $transactionConfig['default_status'] : $rawStatus;
        $lineItems = $document['line_items'] ?? [];
        $documentId = (string) ($document[$zohoConfig['id_field']] ?? '');

        // Vendor lives on the document, not the line item; only meaningful
        // (and only a real column) for purchase orders — the Purchase
        // Recommendation report needs it to compare vendors per SKU.
        $vendorName = $type === 'purchase_orders' ? ($document['vendor_name'] ?? null) : null;

        // Human-readable document number (e.g. "84347") and customer, shown
        // on the Reports page so a line item can be traced back to the
        // document it came from — Zoho's own list views are document-level,
        // ours are line-item-level, so these live on every row instead.
        $documentNumber = $document[$zohoConfig['number_field']] ?? null;
        $customerName = $zohoConfig['customer_field'] !== null ? ($document[$zohoConfig['customer_field']] ?? null) : null;

        // Full document-level detail — added 2026-10-09 per explicit ask to
        // show every field Zoho itself carries for these documents, not
        // just the line-item-level subset this mapper captured before.
        // Confirmed live against one real document per type (field names/
        // shapes below match an actual salesorder/purchaseorder/
        // salesreturn/invoice detail response, not Zoho's generic docs).
        // Same value on every line row of one document — see
        // DOCUMENT_LEVEL_EXTRA_KEYS's docblock for why.
        $documentExtra = [
            'reference_number' => $document['reference_number'] ?? null,
            'salesperson_name' => $document['salesperson_name'] ?? null,
            'payment_terms_label' => $document['payment_terms_label'] ?? null,
            'currency_code' => $document['currency_code'] ?? null,
            'notes' => $document['notes'] ?? null,
            'terms' => $document['terms'] ?? null,
            'billing_address' => self::formatAddress($document['billing_address'] ?? null),
            'shipping_address' => self::formatAddress($document['shipping_address'] ?? null),
            'document_sub_total' => $document['sub_total'] ?? null,
            'document_discount_total' => $document['discount_total'] ?? null,
            'document_tax_total' => $document['tax_total'] ?? null,
            'document_shipping_charge' => $document['shipping_charge'] ?? null,
            'document_adjustment' => $document['adjustment'] ?? null,
            'document_total' => $document['total'] ?? null,
            'taxes' => self::simplifyTaxes($document['taxes'] ?? null),
            'custom_fields' => self::simplifyCustomFields($document['custom_fields'] ?? null),
        ];

        if ($type === 'returns') {
            $documentExtra['salesreturn_type'] = $document['salesreturn_type'] ?? null;
            $documentExtra['refund_status'] = $document['refund_status'] ?? null;
            $documentExtra['receive_status'] = $document['receive_status'] ?? null;
        }

        if ($type === 'purchase_orders') {
            $documentExtra['delivery_date'] = $this->toDate($document['delivery_date'] ?? null);
            $documentExtra['expected_delivery_date'] = $this->toDate($document['expected_delivery_date'] ?? null);
            $documentExtra['ship_via'] = $document['ship_via'] ?? null;
            $documentExtra['attention'] = $document['attention'] ?? null;
            $documentExtra['delivery_address'] = self::formatAddress($document['delivery_address'] ?? null);
        }

        if ($type === 'invoices') {
            $documentExtra['due_date'] = $this->toDate($document['due_date'] ?? null);
            $documentExtra['balance'] = $document['balance'] ?? null;
            $documentExtra['payment_made'] = $document['payment_made'] ?? null;
            $documentExtra['is_emailed'] = $document['is_emailed'] ?? null;
        }

        return collect($lineItems)
            ->map(function (array $lineItem) use ($importId, $dateColumn, $date, $status, $skuByItemId, $now, $documentId, $type, $vendorName, $documentNumber, $customerName, $documentExtra) {
                $itemId = (string) ($lineItem['item_id'] ?? '');
                $sku = $lineItem['sku'] ?? $skuByItemId[$itemId] ?? ($itemId !== '' ? "ZOHO-{$itemId}" : '');
                $lineItemId = (string) ($lineItem['line_item_id'] ?? '');

                // Zoho's line_item object carries pricing detail beyond just
                // sku/quantity (confirmed live for purchase_orders, which
                // already reads 'rate' into its own column below) — mirrored
                // here into 'extra' under the same keys the Analytics-backed
                // invoice importers use (item_name/item_price/
                // discount_percent/total), so the "View Details" page renders
                // them identically regardless of which path produced the row.
                $row = [
                    'import_id' => $importId,
                    'zoho_document_id' => $documentId ?: null,
                    'zoho_line_item_id' => $lineItemId ?: null,
                    'document_number' => $documentNumber ?: null,
                    'sku' => $sku,
                    'quantity' => (int) ($lineItem['quantity'] ?? 0),
                    $dateColumn => $date,
                    'status' => strtolower(trim((string) $status)),
                    // discount_amount/sub_total mirror the fields the
                    // Analytics-Excel import (ImportInvoiceItemsFixedReport)
                    // already captures into extra, so live-synced rows carry
                    // the same detail — confirmed live 2026-09-22 that the
                    // live invoice detail response's line_item object really
                    // does have a 'discount_amount' field (not just
                    // 'discount' as a percent string); sub_total (pre-
                    // discount) isn't a distinct API field, so it's computed
                    // from rate*quantity, matching what "Sub Total (BCY)"
                    // meant in the Excel export for a single-currency org.
                    'extra' => json_encode(array_merge($documentExtra, [
                        'zoho_item_id' => $itemId ?: null,
                        'item_name' => $lineItem['name'] ?? $lineItem['item_name'] ?? null,
                        'item_price' => isset($lineItem['rate']) ? (float) $lineItem['rate'] : null,
                        'discount_percent' => isset($lineItem['discount']) ? (float) $lineItem['discount'] : null,
                        'discount_amount' => isset($lineItem['discount_amount']) ? (float) $lineItem['discount_amount'] : null,
                        'sub_total' => isset($lineItem['rate'], $lineItem['quantity']) ? round((float) $lineItem['rate'] * (float) $lineItem['quantity'], 2) : null,
                        'total' => isset($lineItem['item_total']) ? (float) $lineItem['item_total'] : null,
                        'location_id' => $lineItem['location_id'] ?? null,
                    ])),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($type === 'purchase_orders') {
                    // purchase_order_items has no customer_name column — it
                    // has vendor_name instead, set below.
                    $row['vendor_name'] = $vendorName;
                    $row['rate'] = isset($lineItem['rate']) ? (float) $lineItem['rate'] : null;
                    // Zoho's "discount" field on a PO line item — percentage
                    // vs. flat amount hasn't been verified against this org's
                    // live data yet, unlike everything else in this mapper.
                    // Treat this column as provisional until checked.
                    $row['discount_percent'] = isset($lineItem['discount']) ? (float) $lineItem['discount'] : null;
                } else {
                    $row['customer_name'] = $customerName ?: null;

                    // invoice_items is the only line-item table with its own
                    // warehouse column (added for per-branch sales velocity,
                    // see that migration's docblock) — Zoho's line_item
                    // object carries this as location_name (confirmed live
                    // 2026-09-22, e.g. "JEDDAH"), same concept as the "WH"
                    // column the old Analytics export used.
                    if ($type === 'invoices') {
                        $row['warehouse'] = $lineItem['location_name'] ?? null;
                    }
                }

                return $row;
            })
            ->filter(fn (array $row) => $row['sku'] !== '')
            ->values()
            ->all();
    }

    private function toDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function toDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
