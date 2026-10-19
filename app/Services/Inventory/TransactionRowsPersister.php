<?php

namespace App\Services\Inventory;

use App\Models\Import;
use Illuminate\Support\Facades\DB;

/**
 * Shared write path for sale_items/purchase_order_items/return_items rows,
 * used by both the Excel import processor and the Zoho sync service — one
 * persistence routine regardless of where the rows came from. Mirrors
 * InventoryRowsPersister's role for the inventory_items table.
 */
class TransactionRowsPersister
{
    /**
     * @param  array<int, array<string, mixed>>  $itemRows
     */
    public function persist(Import $import, array $itemRows, string $relation, string $table): void
    {
        DB::transaction(function () use ($import, $itemRows, $relation, $table) {
            $import->{$relation}()->delete();
            foreach (array_chunk($itemRows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
            $import->update([
                'status' => 'completed',
                'row_count' => count($itemRows),
                'error_message' => null,
            ]);
        });
    }

    /**
     * Zoho sync path: upserts by (zoho_document_id, zoho_line_item_id)
     * instead of wiping the table, so re-running a sync never loses rows
     * outside the fetched window and never duplicates ones already seen.
     *
     * Unlike persist(), this doesn't touch the Import row — SyncZohoTransactionTypeJob
     * calls this once per small page-chunk (see that class for why) and owns
     * the Import's status/row_count itself across however many chunked calls
     * a type's full sync takes.
     *
     * @param  array<int, array<string, mixed>>  $itemRows
     */
    public function persistIncremental(array $itemRows, string $table, string $dateColumn): void
    {
        foreach (array_chunk($itemRows, 500) as $chunk) {
            // Derived from the actual row shape rather than hardcoded, since
            // purchase_orders rows carry extra columns (vendor_name, rate,
            // discount_percent) that the other three transaction types'
            // tables don't have — a fixed list would either omit those on
            // update or error on tables where the columns don't exist.
            $updateColumns = array_diff(array_keys($chunk[0]), ['zoho_document_id', 'zoho_line_item_id', 'created_at']);

            DB::table($table)->upsert($chunk, ['zoho_document_id', 'zoho_line_item_id'], $updateColumns);
        }
    }
}
