<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use Smalot\PdfParser\Parser as PdfParser;

/**
 * Turns an uploaded file (any format) into text the SmartIngestClient
 * can send to Claude. Images are handled separately — the caller
 * inspects the returned `image_base64` field and passes it to the
 * client as a multimodal block.
 *
 * Why a single extractor: the smart ingest flow should feel like
 * "drop anything in and it works", so all file-type dispatch belongs
 * here, not scattered through the Livewire component.
 */
class SmartIngestExtractor
{
    /**
     * Inspect the file and return either extracted text or a base64
     * image payload (or both if we can't tell which).
     *
     * @return array{text: string, image_base64: ?string, image_media_type: ?string, summary: string}
     */
    public function extract(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => [
                'text' => $this->extractPdf($path),
                'image_base64' => null,
                'image_media_type' => null,
                'summary' => "PDF: {$originalName}",
            ],
            'csv', 'txt' => [
                'text' => $this->readFilePreview($path, 200),
                'image_base64' => null,
                'image_media_type' => null,
                'summary' => "Text/CSV: {$originalName}",
            ],
            'xlsx', 'xls', 'ods' => [
                'text' => $this->extractSpreadsheet($path),
                'image_base64' => null,
                'image_media_type' => null,
                'summary' => "Spreadsheet: {$originalName}",
            ],
            'eml', 'msg' => [
                'text' => $this->extractEmail($path),
                'image_base64' => null,
                'image_media_type' => null,
                'summary' => "Email: {$originalName}",
            ],
            'jpg', 'jpeg', 'png', 'gif', 'webp' => [
                'text' => '',
                'image_base64' => base64_encode((string) file_get_contents($path)),
                'image_media_type' => $this->imageMediaType($ext),
                'summary' => "Image: {$originalName}",
            ],
            default => [
                // Unknown file type — give the model a raw-text fallback.
                'text' => $this->readFilePreview($path, 200),
                'image_base64' => null,
                'image_media_type' => null,
                'summary' => "File: {$originalName}",
            ],
        };
    }

    protected function extractPdf(string $path): string
    {
        $parser = new PdfParser;
        $pdf = $parser->parseFile($path);

        return trim($pdf->getText());
    }

    protected function extractSpreadsheet(string $path): string
    {
        // Re-use the tabular importer to read headers + rows, then
        // stringify as a markdown table so the model sees the column
        // structure explicitly.
        $importer = new TabularImportService;
        $rows = $importer->readRows($path);
        if (empty($rows)) {
            return '';
        }

        $headers = array_keys($rows[0]);
        $lines = [];
        $lines[] = '| '.implode(' | ', $headers).' |';
        $lines[] = '|'.str_repeat('---|', count($headers));
        foreach (array_slice($rows, 0, 200) as $row) {
            $cells = array_map(
                fn ($h) => str_replace(['|', "\n"], [' ', ' '], (string) ($row[$h] ?? '')),
                $headers,
            );
            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines);
    }

    protected function extractEmail(string $path): string
    {
        // .eml files are plain-text MIME. Strip headers, keep body.
        $raw = (string) file_get_contents($path);
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        $body = $parts[1] ?? $raw;
        // De-quote common forward artifacts
        $body = preg_replace('/^>+\s?/m', '', $body) ?? $body;

        return trim($body);
    }

    protected function readFilePreview(string $path, int $maxLines): string
    {
        $raw = (string) file_get_contents($path);
        if ($raw === '') {
            return '';
        }
        $lines = preg_split('/\r?\n/', $raw) ?: [];

        return implode("\n", array_slice($lines, 0, $maxLines));
    }

    protected function imageMediaType(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
