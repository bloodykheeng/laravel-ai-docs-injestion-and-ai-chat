<?php

namespace App\Services;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Imagick;

class SemanticChunkerService
{
    private string $provider;
    private string $embeddingModel;
    private float $similarityThreshold;
    private int $maxTokens;
    private int $minWords;
    private string $extractionMethod;

    public function __construct(
        string $provider = 'ollama',
        string $embeddingModel = 'embeddinggemma',
        float $similarityThreshold = 0.5,
        int $maxTokens = 1500,
        int $minWords = 300,
        string $extractionMethod = 'tesseract' // tesseract, spatie, smalot
    ) {
        $this->provider = $provider;
        $this->embeddingModel = $embeddingModel;
        $this->similarityThreshold = $similarityThreshold;
        $this->maxTokens = $maxTokens;
        $this->minWords = $minWords;
        $this->extractionMethod = $extractionMethod;
    }

    /**
     * Extract text from PDF using specified or fallback methods
     */
    public function extractPdfText($file): array
    {
        // Use specified extraction method first
        try {
            switch ($this->extractionMethod) {
                case 'tesseract':
                    $pages = $this->extractPdfTextTesseract($file);
                    if (!empty($pages)) {
                        return $pages;
                    }
                    break;

                case 'spatie':
                    $text = $this->extractPdfTextSpatie($file);
                    if (!empty(trim($text))) {
                        return [
                            [
                                'page_number' => 1,
                                'text' => $text,
                                'extraction_method' => 'spatie'
                            ]
                        ];
                    }
                    break;

                case 'smalot':
                    $pages = $this->extractPdfTextSmalot($file);
                    if (!empty($pages)) {
                        return $pages;
                    }
                    break;
            }
        } catch (\Exception $e) {
            // Continue to fallback methods
        }

        // Fallback: Try other methods if primary failed
        $methods = ['spatie', 'smalot', 'tesseract'];
        $methods = array_diff($methods, [$this->extractionMethod]);

        foreach ($methods as $method) {
            try {
                switch ($method) {
                    case 'spatie':
                        $text = $this->extractPdfTextSpatie($file);
                        if (!empty(trim($text))) {
                            return [
                                [
                                    'page_number' => 1,
                                    'text' => $text,
                                    'extraction_method' => 'spatie'
                                ]
                            ];
                        }
                        break;

                    case 'smalot':
                        $pages = $this->extractPdfTextSmalot($file);
                        if (!empty($pages)) {
                            return $pages;
                        }
                        break;

                    case 'tesseract':
                        $pages = $this->extractPdfTextTesseract($file);
                        if (!empty($pages)) {
                            return $pages;
                        }
                        break;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        throw new \Exception('Failed to extract text from PDF using all available methods');
    }

    private function extractPdfTextSpatie($file): string
    {
        $text = \Spatie\PdfToText\Pdf::getText($file->getRealPath());
        return $this->sanitizeText($text);
    }

    private function extractPdfTextSmalot($file): array
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($file->getRealPath());

        $pages = [];
        $pageNumber = 1;

        foreach ($pdf->getPages() as $page) {
            $text = $this->sanitizeText($page->getText());

            if (!empty($text)) {
                $pages[] = [
                    'page_number' => $pageNumber,
                    'text' => $text,
                    'extraction_method' => 'smalot'
                ];
            }

            $pageNumber++;
        }

        return $pages;
    }

    private function extractPdfTextTesseract($file): array
    {
        $imagick = new Imagick();
        $imagick->setResolution(600, 600);
        $imagick->readImage($file->getRealPath());

        $pages = [];
        $pageNumber = 1;

        foreach ($imagick as $index => $page) {
            $page->setImageFormat('png');
            $tempImagePath = sys_get_temp_dir() . '/pdf_page_' . $index . '_' . uniqid() . '.png';
            $page->writeImage($tempImagePath);

            // Run Tesseract OCR on each page
            $ocr = new TesseractOCR($tempImagePath);
            $text = $this->sanitizeText($ocr->run());

            if (!empty($text)) {
                $pages[] = [
                    'page_number' => $pageNumber,
                    'text' => $text,
                    'extraction_method' => 'tesseract_ocr'
                ];
            }

            // Clean up temp file
            if (file_exists($tempImagePath)) {
                unlink($tempImagePath);
            }

            $pageNumber++;
        }

        $imagick->clear();

        return $pages;
    }

    // private function splitIntoParagraphs(string $text): array
    // {
    //     // Split by double newlines (paragraph breaks)
    //     return array_filter(
    //         array_map('trim', explode("\n\n", $text)),
    //         fn($p) => !empty($p)
    //     );
    // }

    // private function splitIntoParagraphs(string $text): array
    // {
    //     // Split by double newlines (paragraph breaks)
    //     $paragraphs = array_filter(
    //         array_map('trim', explode("\n\n", $text)),
    //         fn($p) => !empty($p)
    //     );

    //     // Combine paragraphs into chunks of at least minWords
    //     $chunks = [];
    //     $currentChunk = [];
    //     $currentWords = 0;

    //     foreach ($paragraphs as $paragraph) {
    //         $paragraphWords = str_word_count($paragraph);

    //         $currentChunk[] = $paragraph;
    //         $currentWords += $paragraphWords;

    //         // If we've reached minWords, save the chunk
    //         if ($currentWords >= $this->minWords) {
    //             $chunks[] = implode("\n\n", $currentChunk);
    //             $currentChunk = [];
    //             $currentWords = 0;
    //         }
    //     }

    //     // Add any remaining paragraphs
    //     if (!empty($currentChunk)) {
    //         $chunks[] = implode("\n\n", $currentChunk);
    //     }

    //     return $chunks;
    // }

    private function splitIntoParagraphs(string $text): array
    {
        $chunks = [];
        $currentChunk = '';
        $currentWords = 0;

        // Split by any whitespace but keep track of original formatting
        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $part) {
            // Count words in this part
            $wordCount = str_word_count($part);

            $currentChunk .= $part;
            $currentWords += $wordCount;

            // If we've reached minWords, save the chunk
            if ($currentWords >= $this->minWords) {
                $chunks[] = trim($currentChunk);
                $currentChunk = '';
                $currentWords = 0;
            }
        }

        // Add any remaining content
        if (!empty(trim($currentChunk))) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    private function generateEmbeddings(array $paragraphs): array
    {
        $embeddings = [];

        $batchSize = 10;
        $batches = array_chunk($paragraphs, $batchSize);

        foreach ($batches as $batch) {
            $response = Prism::embeddings()
                ->using(Provider::from($this->provider), $this->embeddingModel)
                ->fromArray($batch)
                ->asEmbeddings();

            foreach ($response->embeddings as $embedding) {
                $embeddings[] = $embedding->embedding;
            }

            sleep(1);
        }

        return $embeddings;
    }

    private function generateChunkEmbedding(string $text): array
    {
        $response = Prism::embeddings()
            ->using(Provider::from($this->provider), $this->embeddingModel)
            ->fromArray([$text])
            ->asEmbeddings();

        return $response->embeddings[0]->embedding;
    }

    private function cosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        for ($i = 0; $i < count($vec1); $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $magnitude1 += $vec1[$i] * $vec1[$i];
            $magnitude2 += $vec2[$i] * $vec2[$i];
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }

    private function estimateTokenCount(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Sanitize text to ensure valid UTF-8 encoding
     */
    private function sanitizeText(string $text): string
    {
        // Remove any non-UTF-8 characters
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Remove null bytes
        $text = str_replace("\0", '', $text);

        // Remove invisible control characters except newlines, tabs, and carriage returns
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove excessive whitespace while preserving paragraph breaks
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    // public function splitDocuments(array $documents): array
    // {
    //     $allChunks = [];

    //     foreach ($documents as $doc) {
    //         $text = $doc['page_content'];
    //         $metadata = $doc['metadata'] ?? [];

    //         $paragraphs = $this->splitIntoParagraphs($text);

    //         if (empty($paragraphs)) {
    //             continue;
    //         }

    //         // echo "Generating embeddings for " . count($paragraphs) . " paragraphs...\n";
    //         $embeddings = $this->generateEmbeddings($paragraphs);

    //         $similarities = [];
    //         for ($i = 0; $i < count($embeddings) - 1; $i++) {
    //             $similarities[$i] = $this->cosineSimilarity($embeddings[$i], $embeddings[$i + 1]);
    //         }

    //         $chunks = [];
    //         $currentChunk = [$paragraphs[0]];
    //         $currentTokens = $this->estimateTokenCount($paragraphs[0]);

    //         for ($i = 0; $i < count($paragraphs) - 1; $i++) {
    //             $similarity = $similarities[$i];
    //             $nextParagraph = $paragraphs[$i + 1];
    //             $nextTokens = $this->estimateTokenCount($nextParagraph);

    //             if ($similarity < $this->similarityThreshold || ($currentTokens + $nextTokens) > $this->maxTokens) {
    //                 $chunkText = implode("\n\n", $currentChunk);

    //                 // Generate embedding for the combined chunk
    //                 $chunkEmbedding = $this->generateChunkEmbedding($chunkText);

    //                 $chunks[] = [
    //                     'page_content' => $chunkText,
    //                     'embedding' => $chunkEmbedding,
    //                     'metadata' => array_merge($metadata, [
    //                         'similarity_score' => $similarity,
    //                         'token_count' => $currentTokens,
    //                         'paragraph_count' => count($currentChunk)
    //                     ])
    //                 ];

    //                 $currentChunk = [$nextParagraph];
    //                 $currentTokens = $nextTokens;
    //             } else {
    //                 $currentChunk[] = $nextParagraph;
    //                 $currentTokens += $nextTokens;
    //             }
    //         }

    //         // Handle the last chunk
    //         if (!empty($currentChunk)) {
    //             $chunkText = implode("\n\n", $currentChunk);

    //             // Generate embedding for the final chunk
    //             $chunkEmbedding = $this->generateChunkEmbedding($chunkText);

    //             $chunks[] = [
    //                 'page_content' => $chunkText,
    //                 'embedding' => $chunkEmbedding,
    //                 'metadata' => array_merge($metadata, [
    //                     'token_count' => $currentTokens,
    //                     'paragraph_count' => count($currentChunk)
    //                 ])
    //             ];
    //         }

    //         $allChunks = array_merge($allChunks, $chunks);
    //     }

    //     return $allChunks;
    // }

    public function splitDocuments(array $documents): array
    {
        $allChunks = [];

        foreach ($documents as $doc) {
            $text = $doc['page_content'];
            $metadata = $doc['metadata'] ?? [];

            // This now returns chunks of at least minWords (300 words)
            $chunks = $this->splitIntoParagraphs($text);

            if (empty($chunks)) {
                continue;
            }

            // Generate embeddings for each chunk
            foreach ($chunks as $chunkText) {
                $chunkEmbedding = $this->generateChunkEmbedding($chunkText);
                $tokenCount = $this->estimateTokenCount($chunkText);
                $wordCount = str_word_count($chunkText);

                $allChunks[] = [
                    'page_content' => $chunkText,
                    'embedding' => $chunkEmbedding,
                    'metadata' => array_merge($metadata, [
                        'token_count' => $tokenCount,
                        'word_count' => $wordCount
                    ])
                ];
            }
        }

        return $allChunks;
    }

    /**
     * Process PDF file and return chunks with embeddings and page metadata
     */
    public function processPdfFile($file): array
    {
        $extractedPages = $this->extractPdfText($file);

        // // Throw error to see the actual extracted data
        // throw new \Exception('DEBUG - Extracted Pages: ' . json_encode($extractedPages, JSON_PRETTY_PRINT));

        if (empty($extractedPages)) {
            throw new \Exception('No text could be extracted from the PDF');
        }

        $allChunks = [];

        // Process each page separately to maintain page metadata
        foreach ($extractedPages as $pageData) {
            $document = [
                'page_content' => $pageData['text'],
                'metadata' => [
                    'source' => $file->getClientOriginalName(),
                    'type' => 'pdf',
                    'page_number' => $pageData['page_number'],
                    'extraction_method' => $pageData['extraction_method']
                ]
            ];

            $chunks = $this->splitDocuments([$document]);
            $allChunks = array_merge($allChunks, $chunks);
        }

        return $allChunks;
    }


    /**
     * Set extraction method
     */
    public function setExtractionMethod(string $method): void
    {
        $this->extractionMethod = $method;
    }

    /**
     * Count unique pages across all chunks
     */
    public function countUniquePages(array $chunks): int
    {
        $pages = array_unique(array_map(function ($chunk) {
            return $chunk['metadata']['page_number'] ?? 0;
        }, $chunks));

        return count(array_filter($pages));
    }
}
