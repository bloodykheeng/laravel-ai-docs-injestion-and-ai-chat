<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

class PdfProcessorService
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    public function extractText(string $pdfPath): array
    {
        $pdf = $this->parser->parseFile($pdfPath);
        $pages = $pdf->getPages();
        $documents = [];

        foreach ($pages as $index => $page) {
            $text = $page->getText();

            if (!empty(trim($text))) {
                $documents[] = [
                    'page_content' => $text,
                    'metadata' => [
                        'source' => $pdfPath,
                        'page_number' => $index + 1
                    ]
                ];
            }
        }

        return $documents;
    }

    public function chunkDocuments(array $documents, int $maxTokens = 1500, int $overlap = 100): array
    {
        $chunker = new SemanticChunkerService($maxTokens, $overlap);
        return $chunker->splitDocuments($documents);
    }

    public function saveChunksToFile(array $chunks, string $outputPath): void
    {
        $content = '';

        foreach ($chunks as $i => $chunk) {
            $text = $chunk['page_content'];
            $text = str_replace('•', '-', $text);
            $text = str_replace("\n", "\n\n", $text);

            $pageNumber = $chunk['metadata']['page_number'] ?? 'N/A';
            $meta = "# Chunk " . ($i + 1) . " | Page: {$pageNumber}\n";

            $content .= "\n\n{$meta}{$text}\n";
        }

        file_put_contents($outputPath, $content);
    }

    public function processPdf(string $pdfPath, string $outputPath, int $maxTokens = 1500, int $overlap = 100): array
    {
        $documents = $this->extractText($pdfPath);
        $chunks = $this->chunkDocuments($documents, $maxTokens, $overlap);
        $this->saveChunksToFile($chunks, $outputPath);

        return $chunks;
    }
}
