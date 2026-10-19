<?php

namespace App\Services\Inventory;

use App\Models\ColumnMapping;

class ColumnMappingService
{
    /**
     * Known fields a column can be mapped to, per import type, with the
     * header aliases we auto-recognize and whether the field is required.
     *
     * @return array<string, array{label: string, required: bool, aliases: string[]}>
     */
    public static function knownFields(string $type = 'inventory'): array
    {
        return match ($type) {
            'sales' => [
                'sku' => [
                    'label' => 'SKU',
                    'required' => true,
                    'aliases' => ['sku', 'item sku', 'product sku', 'sku code', 'item code'],
                ],
                'quantity' => [
                    'label' => 'Quantity',
                    'required' => true,
                    'aliases' => ['quantity', 'qty', 'units', 'units ordered', 'order quantity', 'ordered qty'],
                ],
                'order_date' => [
                    'label' => 'Order Date',
                    'required' => true,
                    'aliases' => ['order date', 'date', 'order_date', 'purchase date', 'date ordered', 'sale date'],
                ],
                'status' => [
                    'label' => 'Status',
                    'required' => false,
                    'aliases' => ['status', 'order status', 'order_status'],
                ],
            ],
            'purchase_orders' => [
                'sku' => [
                    'label' => 'SKU',
                    'required' => true,
                    'aliases' => ['sku', 'item sku', 'product sku', 'sku code', 'item code'],
                ],
                'quantity' => [
                    'label' => 'Quantity',
                    'required' => true,
                    'aliases' => ['quantity', 'qty', 'units', 'units ordered', 'order quantity', 'ordered qty'],
                ],
                'order_date' => [
                    'label' => 'Order Date',
                    'required' => true,
                    'aliases' => ['order date', 'date', 'order_date', 'po date', 'purchase order date', 'date ordered'],
                ],
                'status' => [
                    'label' => 'Status',
                    'required' => false,
                    'aliases' => ['status', 'po status', 'order status', 'order_status', 'received'],
                ],
            ],
            'returns' => [
                'sku' => [
                    'label' => 'SKU',
                    'required' => true,
                    'aliases' => ['sku', 'item sku', 'product sku', 'sku code', 'item code'],
                ],
                'quantity' => [
                    'label' => 'Quantity',
                    'required' => true,
                    'aliases' => ['quantity', 'qty', 'units', 'units returned', 'return quantity', 'returned qty'],
                ],
                'order_date' => [
                    'label' => 'Return Date',
                    'required' => true,
                    'aliases' => ['return date', 'date', 'return_date', 'date returned'],
                ],
                'status' => [
                    'label' => 'Reason',
                    'required' => false,
                    'aliases' => ['reason', 'return reason', 'status'],
                ],
            ],
            default => [
                'sku' => [
                    'label' => 'SKU',
                    'required' => true,
                    'aliases' => ['sku', 'item sku', 'product sku', 'sku code', 'item code'],
                ],
                'name' => [
                    'label' => 'Item Name',
                    'required' => true,
                    'aliases' => ['name', 'item name', 'product name', 'item', 'description'],
                ],
                'stock' => [
                    'label' => 'Stock',
                    'required' => true,
                    'aliases' => ['stock', 'quantity', 'qty', 'stock on hand', 'available stock', 'current stock', 'stock quantity'],
                ],
                'price' => [
                    'label' => 'Price',
                    'required' => true,
                    'aliases' => ['price', 'selling price', 'unit price', 'rate', 'sale price', 'sales price'],
                ],
                'cost' => [
                    'label' => 'Cost',
                    'required' => false,
                    'aliases' => ['cost', 'cost price', 'purchase price', 'unit cost'],
                ],
                'category' => [
                    'label' => 'Category',
                    'required' => false,
                    'aliases' => ['category', 'item category', 'product category', 'group'],
                ],
                'warehouse' => [
                    'label' => 'Warehouse',
                    'required' => false,
                    'aliases' => ['warehouse', 'location', 'store', 'branch'],
                ],
            ],
        };
    }

    /**
     * Selectable mapping targets, including the non-inventory-field options.
     *
     * @return array<string, string>
     */
    public static function selectableOptions(string $type = 'inventory'): array
    {
        $options = collect(self::knownFields($type))->map(fn ($f) => $f['label'])->all();
        $options['extra'] = 'Store as extra field (keep the data, no dedicated column)';
        $options['ignore'] = 'Ignore this column';

        return $options;
    }

    public static function requiredFields(string $type = 'inventory'): array
    {
        return collect(self::knownFields($type))
            ->filter(fn ($f) => $f['required'])
            ->keys()
            ->all();
    }

    public function normalizeHeader(string $header): string
    {
        $normalized = strtolower(trim($header));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * A stable signature for a given set of headers, so repeated uploads
     * from the same source file layout are recognized automatically.
     * Scoped by type so an inventory file and a sales file never collide
     * even if a header set happened to look similar.
     *
     * @param  string[]  $headers
     */
    public function computeSourceSignature(array $headers, string $type = 'inventory'): string
    {
        $normalized = array_map($this->normalizeHeader(...), $headers);
        sort($normalized);

        return sha1($type.'|'.implode('|', $normalized));
    }

    /**
     * Best-guess field for a header we've never seen mapped before, based
     * on known aliases. Returns null if nothing matches confidently.
     */
    public function guessField(string $header, string $type = 'inventory'): ?string
    {
        $normalized = $this->normalizeHeader($header);

        foreach (self::knownFields($type) as $field => $config) {
            if (in_array($normalized, $config['aliases'], true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Previously confirmed mapping for this exact source, keyed by the
     * original column header text.
     *
     * @return array<string, string>
     */
    public function getRememberedMapping(string $sourceSignature): array
    {
        return ColumnMapping::where('source_signature', $sourceSignature)
            ->pluck('mapped_field', 'source_column_name')
            ->all();
    }

    /**
     * Whether every given header already has a remembered mapping, meaning
     * this upload doesn't need to prompt the admin again.
     *
     * @param  string[]  $headers
     */
    public function isFullyMapped(string $sourceSignature, array $headers): bool
    {
        $remembered = $this->getRememberedMapping($sourceSignature);

        foreach ($headers as $header) {
            if (! array_key_exists($header, $remembered)) {
                return false;
            }
        }

        return count($headers) > 0;
    }

    /**
     * Persist a confirmed mapping so future uploads from the same source
     * never have to ask again.
     *
     * @param  array<string, string>  $mapping  source_column_name => mapped_field
     */
    public function saveMapping(string $sourceSignature, array $mapping, ?int $userId): void
    {
        foreach ($mapping as $sourceColumn => $mappedField) {
            ColumnMapping::updateOrCreate(
                [
                    'source_signature' => $sourceSignature,
                    'source_column_name' => $sourceColumn,
                ],
                [
                    'mapped_field' => $mappedField,
                    'created_by' => $userId,
                ]
            );
        }
    }
}
