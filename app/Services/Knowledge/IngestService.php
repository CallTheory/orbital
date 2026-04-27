<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pgvector\Laravel\Vector;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Takes raw source material (a file, a URL, or pasted text), extracts
 * and chunks the text, embeds each chunk, and writes the rows into a
 * knowledge store.
 *
 * Intentionally synchronous — run from the IngestKnowledgeJob on the
 * queue so the Filament request returns quickly. The ingest pipeline is
 * allowed to be slow (embeddings take time, large PDFs take time).
 */
class IngestService
{
    /** Target chunk size in characters (~250 tokens). */
    private const CHUNK_CHARS = 1000;

    /** Soft upper bound — paragraphs longer than this get hard-split. */
    private const CHUNK_HARD_MAX = 1500;

    /** Overlap used when a long paragraph has to be hard-split. */
    private const CHUNK_OVERLAP = 200;

    public function __construct(
        private readonly EmbeddingService $embeddings,
        private readonly IndexMaintenance $indexMaintenance,
    ) {}

    /**
     * Ingest a file from disk into the store.
     */
    public function ingestFile(KnowledgeStore $store, string $path, string $label): int
    {
        $text = $this->extractTextFromFile($path);

        return $this->ingestText($store, $text, sourceType: 'file', sourceRef: $label);
    }

    /**
     * Ingest a URL's body text.
     */
    public function ingestUrl(KnowledgeStore $store, string $url): int
    {
        $response = Http::timeout(30)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("Failed to fetch URL: HTTP {$response->status()}");
        }

        $text = $this->stripHtml($response->body());

        return $this->ingestText($store, $text, sourceType: 'url', sourceRef: $url);
    }

    /**
     * Ingest raw text, chunking and embedding it.
     */
    public function ingestText(
        KnowledgeStore $store,
        string $text,
        string $sourceType = 'text',
        ?string $sourceRef = null,
    ): int {
        $store->update(['ingest_status' => 'processing']);

        try {
            $chunks = $this->chunk($text);
            if (empty($chunks)) {
                $store->update(['ingest_status' => 'idle']);

                return 0;
            }

            $vectors = $this->embeddings->embedBatch($chunks, $store->embedding_model);

            // Sanity check: the provider's actual output dim should match
            // what the store thinks it is. If it doesn't, we refuse to
            // write anything rather than corrupt the table.
            $actualDims = count($vectors[0] ?? []);
            if ($actualDims !== (int) $store->embedding_dims) {
                throw new RuntimeException(
                    "Embedding dimension mismatch for store #{$store->id}: "
                    ."store says {$store->embedding_dims}, provider returned {$actualDims}. "
                    .'Update the store\'s embedding_dims before ingesting.'
                );
            }

            $inserted = 0;
            DB::transaction(function () use ($store, $chunks, $vectors, $sourceType, $sourceRef, &$inserted) {
                $startIndex = (int) $store->chunks()->max('chunk_index') + 1;
                foreach ($chunks as $i => $content) {
                    KnowledgeChunk::create([
                        'store_id' => $store->id,
                        'source_type' => $sourceType,
                        'source_ref' => $sourceRef,
                        'chunk_index' => $startIndex + $i,
                        'content' => $content,
                        'embedding' => new Vector($vectors[$i]),
                        'metadata' => null,
                    ]);
                    $inserted++;
                }

                $store->refresh();
                $store->update([
                    'chunk_count' => $store->chunks()->count(),
                    'ingest_status' => 'idle',
                ]);
            });

            // Build or refresh the per-dimensionality ANN index once
            // the store crosses the threshold. Cheap no-op below it.
            $this->indexMaintenance->ensureAnnIndex($store->fresh());

            return $inserted;
        } catch (\Throwable $e) {
            Log::error('knowledge ingest failed', [
                'store_id' => $store->id,
                'source_type' => $sourceType,
                'source_ref' => $sourceRef,
                'error' => $e->getMessage(),
            ]);
            $store->update(['ingest_status' => 'failed']);
            throw $e;
        }
    }

    /**
     * Pull raw text out of an uploaded file. We do the bare minimum here:
     * PDFs go through smalot/pdfparser, everything else is read as UTF-8
     * text. Rich-document support (docx, html-with-layout) comes later.
     */
    private function extractTextFromFile(string $path): string
    {
        if (! file_exists($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            $parser = new PdfParser;

            return $parser->parseFile($path)->getText();
        }

        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    /**
     * Paragraph-aware chunker. Splits on blank lines first, then greedily
     * packs paragraphs into chunks up to {@see CHUNK_CHARS}. Paragraphs
     * longer than {@see CHUNK_HARD_MAX} get a sliding-window hard-split
     * as a fallback so we never return chunks that are too big to embed.
     *
     * This beats a pure sliding-window chunker because:
     *   - Retrieved chunks usually correspond to semantically coherent
     *     prose blocks (paragraphs) rather than sentences cut in half.
     *   - Titles and short statements don't get padded with unrelated
     *     surrounding text.
     *
     * @return array<int, string>
     */
    private function chunk(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // Normalize line endings but PRESERVE blank lines, which are our
        // paragraph boundaries.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Split on runs of 2+ newlines, then collapse inline whitespace
        // within each paragraph so trailing indentation / tabs don't
        // throw off length calculations.
        $paragraphs = preg_split('/\n{2,}/u', $text) ?: [];
        $paragraphs = array_values(array_filter(array_map(
            fn (string $p): string => trim(preg_replace('/\s+/u', ' ', $p) ?? ''),
            $paragraphs,
        )));

        if (empty($paragraphs)) {
            return [];
        }

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraphLength = mb_strlen($paragraph);

            // Overlong paragraph → hard-split it on its own, flushing
            // whatever we were building first.
            if ($paragraphLength > self::CHUNK_HARD_MAX) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach ($this->hardSplit($paragraph) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            // Adding this paragraph would push the current chunk over the
            // soft limit — flush and start fresh.
            if ($current !== '' && mb_strlen($current) + 2 + $paragraphLength > self::CHUNK_CHARS) {
                $chunks[] = $current;
                $current = $paragraph;

                continue;
            }

            $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Fallback character-window splitter for pathologically-long
     * paragraphs. Keeps the ingest pipeline resilient without ballooning
     * chunk size.
     *
     * @return array<int, string>
     */
    private function hardSplit(string $paragraph): array
    {
        $pieces = [];
        $length = mb_strlen($paragraph);
        $start = 0;
        $step = self::CHUNK_CHARS - self::CHUNK_OVERLAP;

        while ($start < $length) {
            $pieces[] = mb_substr($paragraph, $start, self::CHUNK_CHARS);
            $start += $step;
        }

        return $pieces;
    }

    /**
     * Strip HTML tags and collapse whitespace from a fetched URL body.
     * Good enough for text-heavy pages; will miss SPA content.
     */
    private function stripHtml(string $html): string
    {
        // Drop scripts + styles entirely so their contents don't show up as "text".
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
