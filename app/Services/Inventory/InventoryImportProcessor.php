<?php

namespace App\Services\Inventory;

use App\Models\Import;
use Illuminate\Support\Facades\Storage;
use Throwable;

class InventoryImportProcessor
{
    public function __construct(
        private readonly ExcelImportService $excel,
        private readonly ColumnMappingService $mappings,
        private readonly InventoryRowsPersister $persister,
    ) {}

    /**
     * Turn a confirmed column mapping into stored inventory_items rows.
     *
     * @param  array<string, string>  $mapping  source_column_name => mapped_field
     *
     * @throws \RuntimeException if a required field isn't mapped
     */
    public function process(Import $import, array $mapping): void
    {
        $missing = array_diff(ColumnMappingService::requiredFields('inventory'), array_values($mapping));
        if (! empty($missing)) {
            $labels = collect($missing)->map(fn ($f) => ColumnMappingService::knownFields('inventory')[$f]['label'])->implode(', ');
            throw new \RuntimeException("Missing required column(s): {$labels}");
        }

        $import->update(['status' => 'processing']);

        try {
            [$headers, $rows] = $this->excel->read(Storage::path($import->stored_path));

            $fieldByColumnIndex = [];
            foreach ($headers as $index => $header) {
                $fieldByColumnIndex[$index] = $mapping[$header] ?? 'ignore';
            }

            $now = now();
            $itemRows = [];

            foreach ($rows as $row) {
                $item = [
                    'import_id' => $import->id,
                    'sku' => null,
                    'name' => null,
                    'stock' => 0,
                    'price' => 0,
                    'cost' => null,
                    'category' => null,
                    'warehouse' => null,
                    'extra' => [],
                ];

                foreach ($fieldByColumnIndex as $index => $field) {
                    $value = $row[$index] ?? null;

                    match ($field) {
                        'sku' => $item['sku'] = trim((string) $value),
                        'name' => $item['name'] = trim((string) $value),
                        'stock' => $item['stock'] = $this->toInt($value),
                        'price' => $item['price'] = $this->toDecimal($value),
                        'cost' => $item['cost'] = $value === null || $value === '' ? null : $this->toDecimal($value),
                        'category' => $item['category'] = $value === null || $value === '' ? null : trim((string) $value),
                        'warehouse' => $item['warehouse'] = $value === null || $value === '' ? null : trim((string) $value),
                        'extra' => $item['extra'][$headers[$index]] = $value,
                        default => null,
                    };
                }

                if ($item['sku'] === '' || $item['name'] === '') {
                    continue;
                }

                $item['extra'] = json_encode($item['extra']);
                $item['created_at'] = $now;
                $item['updated_at'] = $now;
                $itemRows[] = $item;
            }

            $this->persister->persist($import, $itemRows);
        } catch (Throwable $e) {
            $import->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function toInt(mixed $value): int
    {
        $cleaned = preg_replace('/[^0-9\-]/', '', (string) $value);

        return (int) $cleaned;
    }

    private function toDecimal(mixed $value): float
    {
        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return (float) $cleaned;
    }
}
