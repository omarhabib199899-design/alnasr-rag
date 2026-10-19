<?php

namespace App\Services\Reports;

use App\Models\PurchaseRecommendation;
use App\Models\PurchaseRecommendationZohoLink;
use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoCustomModuleClient;

/**
 * Pushes one Purchase Recommendation row to Zoho (create or update,
 * whichever applies) and records that it was successfully sent. Shared by
 * the synchronous single-SKU diagnostic path (--push-one) and the chunked
 * background job that handles the real bulk push.
 */
class PurchaseRecommendationPusher
{
    /**
     * Our computed row key => Zoho custom field API name. Best guess based
     * on Zoho's standard label-to-cf_-name convention — not yet confirmed
     * against this org's actual module beyond what --push-one already
     * verified (SKU 1 pushed successfully with every one of these names).
     */
    public const FIELD_MAP = [
        'sku' => 'cf_sku',
        'item_name' => 'cf_item_name',
        'warehouse' => 'cf_warehouse',
        'top_warehouse_name' => 'cf_top_warehouse_name',
        'top_warehouse_stock' => 'cf_top_warehouse_stock',
        'warehouse_count' => 'cf_warehouse_count',
        'current_stock' => 'cf_current_stock',
        'sold_6mo' => 'cf_sold_6mo',
        'stockout_count_1yr' => 'cf_stockout_count_1yr',
        'customer_count_6mo' => 'cf_customer_count_6mo',
        'top_customer_order_qty' => 'cf_top_customer_order_qty',
        'top_customer_name' => 'cf_top_customer_name',
        'top_pharmacy_order_qty' => 'cf_top_pharmacy_order_qty',
        'top_pharmacy_name' => 'cf_top_pharmacy_name',
        'expired_quantity' => 'cf_expired_quantity',
        'pending_po' => 'cf_pending_po',
        'total_available' => 'cf_total_available',
        'suggested_purchase' => 'cf_suggested_purchase',
        'stock_status' => 'cf_stock_status',
        'price' => 'cf_price',
        'cost' => 'cf_cost',
        'estimated_profit_6mo' => 'cf_estimated_profit_6mo',
        'best_discount_vendor' => 'cf_best_discount_vendor',
        'best_discount_price' => 'cf_best_discount_price',
        'lowest_price_vendor' => 'cf_lowest_price_vendor',
        'lowest_price_value' => 'cf_lowest_price_value',
        'most_purchased_vendor' => 'cf_most_purchased_vendor',
        'most_purchased_price' => 'cf_most_purchased_price',
        'value' => 'cf_value',
    ];

    /**
     * Confirmed against this org's live API — /inventory/v1/cm_purchase_recommendation
     * successfully accepted a create with this exact module name.
     */
    public const MODULE_NAME = 'cm_purchase_recommendation';

    public function __construct(private readonly ZohoCustomModuleClient $zohoModule) {}

    /**
     * @param  array<string, mixed>  $row
     * @return array{fields: array<string, mixed>, record_id: string}
     */
    public function push(ZohoConnection $connection, array $row): array
    {
        $fields = $this->mapFields($row);
        $existing = PurchaseRecommendationZohoLink::where('sku', $row['sku'])->first();

        if ($existing) {
            $this->zohoModule->update($connection, self::MODULE_NAME, $existing->zoho_record_id, $fields);
            $recordId = $existing->zoho_record_id;
        } else {
            $recordId = $this->zohoModule->create($connection, self::MODULE_NAME, $fields);
            PurchaseRecommendationZohoLink::create(['sku' => $row['sku'], 'zoho_record_id' => $recordId]);
        }

        PurchaseRecommendation::where('sku', $row['sku'])->update(['zoho_pushed_hash' => $this->hash($fields)]);

        return ['fields' => $fields, 'record_id' => $recordId];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function mapFields(array $row): array
    {
        $fields = [];
        foreach (self::FIELD_MAP as $rowKey => $zohoField) {
            if (($row[$rowKey] ?? null) !== null) {
                $fields[$zohoField] = $row[$rowKey];
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function hashRow(array $row): string
    {
        return $this->hash($this->mapFields($row));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function hash(array $fields): string
    {
        return md5(json_encode($fields));
    }
}
