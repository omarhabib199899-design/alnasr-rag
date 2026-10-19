<?php

namespace App\Services\Inventory;

use App\Models\Import;
use Illuminate\Support\Facades\DB;

/**
 * Shared write path for inventory_items rows, used by both the Excel import
 * processor and the Zoho sync service — one persistence routine regardless
 * of where the rows came from.
 */
class InventoryRowsPersister
{
    /**
     * @param  array<int, array<string, mixed>>  $itemRows
     */
    public function persist(Import $import, array $itemRows): void
    {
        DB::transaction(function () use ($import, $itemRows) {
            $import->items()->delete();
            foreach (array_chunk($itemRows, 500) as $chunk) {
                DB::table('inventory_items')->insert($chunk);
            }
            $import->update([
                'status' => 'completed',
                'row_count' => count($itemRows),
                'error_message' => null,
            ]);
        });
    }

    /**
     * Zoho sync path: inserts one page's worth of rows into a brand-new
     * Import's rows (no delete — this import has no rows yet by
     * definition), incrementing its row_count. Used by
     * SyncZohoInventoryJob's chunked fetch; the caller finalizes (marks
     * completed, deletes the previous generation's rows) once every page
     * is in.
     *
     * @param  array<int, array<string, mixed>>  $itemRows
     */
    public function insertChunk(Import $import, array $itemRows): void
    {
        foreach (array_chunk($itemRows, 500) as $chunk) {
            DB::table('inventory_items')->insert($chunk);
        }

        $import->increment('row_count', count($itemRows));
    }
}
