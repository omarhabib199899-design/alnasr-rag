<?php

namespace App\Services\Inventory;

use App\Models\Import;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Handles every "sku + quantity + date + optional status" shaped import —
 * sales, purchase orders, and returns are structurally identical, just
 * different tables and default status values. Column-mapping field keys
 * (sku, quantity, order_date, status) stay the same across all three; only
 * their display labels differ per type (see ColumnMappingService).
 *
 * CONFIG is public so the Zoho sync path (ZohoTransactionMapper) can reuse
 * the same table/relation/status knowledge instead of redefining it.
 */
class TransactionImportProcessor
{
    public const CONFIG = [
        'sales' => [
            'table' => 'sale_items',
            'relation' => 'saleItems',
            'date_column' => 'order_date',
            'default_status' => 'completed',
        ],
        'purchase_orders' => [
            'table' => 'purchase_order_items',
            'relation' => 'purchaseOrderItems',
            'date_column' => 'transaction_date',
            'default_status' => 'ordered',
        ],
        'returns' => [
            'table' => 'return_items',
            'relation' => 'returnItems',
            'date_column' => 'transaction_date',
            'default_status' => 'processed',
        ],
        'invoices' => [
            'table' => 'invoice_items',
            'relation' => 'invoiceItems',
            'date_column' => 'invoice_date',
            'default_status' => 'completed',
        ],
    ];

    public function __construct(
        private readonly ExcelImportService $excel,
        private readonly TransactionRowsPersister $persister,
    ) {}

    public function supports(string $type): bool
    {
        return array_key_exists($type, self::CONFIG);
    }

    /**
     * @param  array<string, string>  $mapping  source_column_name => mapped_field
     *
     * @throws \RuntimeException if a required field isn't mapped
     */
    public function process(Import $import, array $mapping): void
    {
        $config = self::CONFIG[$import->type] ?? throw new InvalidArgumentException("Unknown transaction type: {$import->type}");

        $missing = array_diff(ColumnMappingService::requiredFields($import->type), array_values($mapping));
        if (! empty($missing)) {
            $labels = collect($missing)->map(fn ($f) => ColumnMappingService::knownFields($import->type)[$f]['label'])->implode(', ');
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
            $dateColumn = $config['date_column'];
            $itemRows = [];

            foreach ($rows as $row) {
                $item = [
                    'import_id' => $import->id,
                    'sku' => null,
                    'quantity' => 0,
                    $dateColumn => null,
                    'status' => $config['default_status'],
                    'extra' => [],
                ];

                foreach ($fieldByColumnIndex as $index => $field) {
                    $value = $row[$index] ?? null;

                    match ($field) {
                        'sku' => $item['sku'] = trim((string) $value),
                        'quantity' => $item['quantity'] = $this->toInt($value),
                        'order_date' => $item[$dateColumn] = $this->toDate($value),
                        'status' => $item['status'] = $value === null || $value === '' ? $config['default_status'] : strtolower(trim((string) $value)),
                        'extra' => $item['extra'][$headers[$index]] = $value,
                        default => null,
                    };
                }

                if ($item['sku'] === '' || $item[$dateColumn] === null) {
                    continue;
                }

                $item['extra'] = json_encode($item['extra']);
                $item['created_at'] = $now;
                $item['updated_at'] = $now;
                $itemRows[] = $item;
            }

            $this->persister->persist($import, $itemRows, $config['relation'], $config['table']);
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
}
