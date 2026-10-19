<?php

namespace App\Services\Zoho;

/**
 * Dr. Ahmed's Zoho Analytics Query Tables, run on our side over the
 * zoho_raw_* copies (see ZohoRawTables, SyncZohoRawTables). Each produces
 * zoho_<key> with the same columns the Lovable portal's zoho.<key> has.
 *
 * Order matters: a query may read an earlier one's output (AR Risk
 * Classification reads AR SALES and AR Risk Reconciliation). Groups follow
 * the Lovable portal's scheduler: hourly 08:00–19:00 (skipped Friday) and
 * nightly from 23:00 in the order Collection → Documents → Profitability →
 * Sales.
 */
class ZohoQueries
{
    public const DIR = 'zoho-analytics-queries';

    public static function all(): array
    {
        return [
            'ar_sales' => ['zoho_name' => 'AR SALES', 'group' => 'hourly',
                'indexes' => [['customer_id', 'category', 'transaction_date'], ['category', 'transaction_date'], ['entity_type'], ['transaction_id']]],
            'ar_risk_reconciliation' => ['zoho_name' => 'AR Risk Reconciliation', 'group' => 'hourly', 'indexes' => [['customer_id']]],
            'ar_risk_classification' => ['zoho_name' => 'AR Risk Classification', 'group' => 'hourly', 'indexes' => [['customer_id']]],
            'sales_orders_not_invoiced' => ['zoho_name' => 'Sales Orders Not Invoiced', 'group' => 'hourly', 'indexes' => [['customer_id'], ['sales_order_id']]],
            'sales_item_performance' => ['zoho_name' => 'Sales Item Performance', 'group' => 'hourly',
                'indexes' => [['customer_id', 'transaction_date'], ['item_id'], ['transaction_date']]],
            'sales_operations' => ['zoho_name' => 'Sales Operations', 'group' => 'hourly',
                'indexes' => [['invoice_id'], ['invoice_number', 'customer_id']]],
            'customer_payment' => ['zoho_name' => 'Customer Payment', 'group' => 'hourly',
                'indexes' => [['payment_id'], ['customer_id'], ['payment_date']]],
            'collection_classification_staging' => ['zoho_name' => 'Collection_Classification_Staging', 'group' => 'nightly', 'indexes' => [['customer_id']]],
            'collection_classification' => ['zoho_name' => 'Collection_Classification', 'group' => 'nightly', 'indexes' => [['customer_id']]],
            'documents_classification' => ['zoho_name' => 'Documents_Classification', 'group' => 'nightly', 'indexes' => [['customer_id']]],
            'profitability_staging' => ['zoho_name' => 'Profitability_Staging', 'group' => 'nightly', 'indexes' => [['customer_id']]],
            'profitability_classification' => ['zoho_name' => 'Profitability_Classification', 'group' => 'nightly', 'indexes' => [['customer_id']]],
            'sales_classification_staging' => ['zoho_name' => 'Sales_Classification_Staging', 'group' => 'nightly', 'indexes' => [['customer_number']]],
            'sales_classification' => ['zoho_name' => 'Sales_Classification', 'group' => 'nightly', 'indexes' => [['customer_number']]],
        ];
    }

    public static function table(string $key): string
    {
        return 'zoho_'.$key;
    }

    /** Zoho table/query name → our MySQL table, for ZohoQueryTranslator. */
    public static function tableMap(): array
    {
        $map = [];
        foreach (ZohoRawTables::all() as $key => $def) {
            $map[$def['name']] = ZohoRawTables::table($key);
        }
        foreach (self::all() as $key => $def) {
            $map[$def['zoho_name']] = self::table($key);
        }

        return $map;
    }

    /** MySQL text to run for a query: the hand-written override if one exists, else the translated original. */
    public static function mysql(string $key): string
    {
        $override = database_path(self::DIR."/mysql/{$key}.sql");
        if (is_file($override)) {
            return file_get_contents($override);
        }

        return (new ZohoQueryTranslator(self::tableMap()))->translate(file_get_contents(database_path(self::DIR."/{$key}.sql")));
    }
}
