<?php

namespace App\Console\Commands;

use App\Models\ItemOwner;
use App\Services\Inventory\ExcelImportService;
use Illuminate\Console\Command;

/**
 * One-time (re-runnable) import of the "Items" Zoho Analytics query table —
 * a manual .xlsx export (see item_owners migration for why this isn't the
 * live Zoho Inventory API's Items — that response has no Owner field).
 * Column list confirmed live 2026-09-27 against the real export: Item ID,
 * Item Name, Status, SKU, Purchase Account, Sales Account, Type, Purchase
 * Price, Usage unit, Sales Price, Tax ID, Returnable Items, Last Modified
 * Time, Created Time, Marketing Company, VENDOR, RSP, OWNER, SOURCE_1,
 * SOURCE OFFER, SOURCE INV OFFER, PROJECT, PACK UNITS, SCIENTIFIC NAME,
 * Reorder Level, DEAL QTY, DEAL VALUE — only Item ID/SKU/Item Name/OWNER are
 * used, the rest aren't needed for the Item Performance tab.
 *
 * Upserts by zoho_item_id, so re-running after a fresh export just updates
 * existing rows rather than duplicating.
 */
class ImportItemOwnersReport extends Command
{
    protected $signature = 'reports:import-item-owners {path : Absolute path to the exported .xlsx file}';

    protected $description = "Import (or refresh) item-to-Owner mapping from a Zoho Analytics 'Items' view export";

    public function handle(ExcelImportService $importService): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        ini_set('memory_limit', '512M');

        [$headers, $rows] = $importService->read($path);
        $index = $this->matchColumns($headers);

        if (! isset($index['zoho_item_id'])) {
            $this->error('Could not find "Item ID" column. Headers seen: '.implode(', ', $headers));

            return self::FAILURE;
        }

        $now = now();
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $zohoItemId = trim((string) ($row[$index['zoho_item_id']] ?? ''));

            if ($zohoItemId === '') {
                $skipped++;

                continue;
            }

            ItemOwner::query()->updateOrCreate(
                ['zoho_item_id' => $zohoItemId],
                [
                    'sku' => isset($index['sku']) ? trim((string) ($row[$index['sku']] ?? '')) ?: null : null,
                    'item_name' => isset($index['item_name']) ? trim((string) ($row[$index['item_name']] ?? '')) ?: null : null,
                    'owner' => isset($index['owner']) ? trim((string) ($row[$index['owner']] ?? '')) ?: null : null,
                    'updated_at' => $now,
                ],
            );
            $imported++;
        }

        $this->info("Done — {$imported} items imported/updated, {$skipped} rows skipped (missing Item ID).");
        $this->info('Total items now in table: '.ItemOwner::count());
        $this->info('Items with a non-empty Owner: '.ItemOwner::whereNotNull('owner')->where('owner', '!=', '')->count());

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int>
     */
    private function matchColumns(array $headers): array
    {
        $wanted = [
            'zoho_item_id' => 'Item ID',
            'item_name' => 'Item Name',
            'sku' => 'SKU',
            'owner' => 'OWNER',
        ];

        $lookup = [];
        foreach ($headers as $i => $header) {
            $lookup[$this->normalize($header)] = $i;
        }

        $index = [];
        foreach ($wanted as $field => $header) {
            if (isset($lookup[$this->normalize($header)])) {
                $index[$field] = $lookup[$this->normalize($header)];
            }
        }

        return $index;
    }

    private function normalize(string $header): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $header)));
    }
}
