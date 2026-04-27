<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Reads CSV and Excel (.xlsx/.xls) files into header + row arrays for
 * the DirectoryEntry import flow. The Filament import
 * action uses this to auto-detect headers (so we can offer a column
 * mapping form) and then stream all rows back for persistence.
 *
 * Format detection is extension-based — callers already know whether
 * the uploaded file is CSV or XLSX. If we ever need to sniff the
 * magic bytes, add it here rather than at every call site.
 */
class TabularImportService
{
    /**
     * Read just the header row from the file. Used to seed the column
     * mapping form without loading the whole spreadsheet into memory.
     *
     * @return array<int, string>
     */
    public function readHeaders(string $path): array
    {
        $format = $this->detectFormat($path);

        if ($format === 'csv') {
            $csv = Reader::createFromPath($path, 'r');
            $csv->setHeaderOffset(0);

            return $csv->getHeader();
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $headers[] = (string) $cell->getValue();
            }
            break;
        }

        return array_values(array_filter($headers, fn ($h) => $h !== ''));
    }

    /**
     * Read every row (excluding the header) into an array of
     * associative arrays keyed by column header.
     *
     * @return array<int, array<string, string>>
     */
    public function readRows(string $path): array
    {
        $format = $this->detectFormat($path);

        if ($format === 'csv') {
            $csv = Reader::createFromPath($path, 'r');
            $csv->setHeaderOffset(0);

            return array_values(iterator_to_array($csv->getRecords()));
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [];
        $rows = [];
        $firstRow = true;

        foreach ($sheet->getRowIterator() as $row) {
            $values = [];
            foreach ($row->getCellIterator() as $cell) {
                $values[] = $cell->getValue() !== null ? (string) $cell->getValue() : '';
            }

            if ($firstRow) {
                $headers = $values;
                $firstRow = false;

                continue;
            }

            // Skip blank rows (all cells empty)
            if (trim(implode('', $values)) === '') {
                continue;
            }

            $keyed = [];
            foreach ($headers as $i => $header) {
                if ($header === '') {
                    continue;
                }
                $keyed[$header] = $values[$i] ?? '';
            }
            $rows[] = $keyed;
        }

        return $rows;
    }

    /**
     * Apply a column mapping to raw rows, producing rows keyed by the
     * target field slugs. Rows where every mapped field is blank are
     * dropped; empty mapped fields are stripped so null-safe defaults
     * on the model do the right thing.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<string, string>  $mapping  header → target slug (or '' to skip)
     * @return array<int, array<string, string>>
     */
    public function applyMapping(array $rows, array $mapping): array
    {
        $out = [];
        foreach ($rows as $row) {
            $mapped = [];
            foreach ($mapping as $header => $target) {
                if ($target === '' || $target === null) {
                    continue;
                }
                $value = trim((string) ($row[$header] ?? ''));
                if ($value === '') {
                    continue;
                }
                $mapped[$target] = $value;
            }
            if (! empty($mapped)) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    private function detectFormat(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'csv', 'txt' => 'csv',
            'xlsx', 'xls', 'ods' => 'xlsx',
            default => throw new RuntimeException("Unsupported import format: .{$ext}"),
        };
    }
}
