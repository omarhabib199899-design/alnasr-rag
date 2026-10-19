<?php

namespace App\Services\Inventory;

use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelImportService
{
    /**
     * Read a spreadsheet (xlsx, xls, or csv — layouts vary by source) into a
     * clean header row plus data rows. Empty trailing columns/rows are
     * dropped so a signature isn't thrown off by stray blank cells.
     *
     * @return array{0: string[], 1: array<int, array<int, mixed>>}
     */
    public function read(string $absolutePath): array
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        $grid = $sheet->toArray(null, true, true, false);

        if (empty($grid)) {
            return [[], []];
        }

        $rawHeaders = array_shift($grid);

        $columnIndexes = [];
        $headers = [];
        foreach ($rawHeaders as $index => $header) {
            $header = trim((string) $header);
            if ($header === '') {
                continue;
            }
            $columnIndexes[] = $index;
            $headers[] = $header;
        }

        $rows = [];
        foreach ($grid as $row) {
            $values = [];
            $isEmpty = true;
            foreach ($columnIndexes as $index) {
                $value = $row[$index] ?? null;
                if ($value !== null && $value !== '') {
                    $isEmpty = false;
                }
                $values[] = $value;
            }
            if (! $isEmpty) {
                $rows[] = $values;
            }
        }

        return [$headers, $rows];
    }
}
