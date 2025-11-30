<?php

namespace App\Services;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Illuminate\Support\Str;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Imagick;

class AgenticChunkerService
{
    private array $chunks = [];
    private array $actions = []; // Track all actions
    private string $provider;
    private string $model;
    private string $embeddingModel;
    private int $chunkIdLength = 5;
    private string $extractionMethod;

    public function __construct(
        string $provider = 'ollama',
        string $model = 'gpt-oss:120b-cloud',
        string $embeddingModel = 'embeddinggemma',
        string $extractionMethod = 'tesseract'
    ) {
        $this->provider = $provider;
        $this->model = $model;
        $this->embeddingModel = $embeddingModel;
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
        $imagick->setResolution(300, 300);
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

    /**
     * Generate embedding for text
     */
    private function generateEmbedding(string $text): array
    {
        $response = Prism::embeddings()
            ->using(Provider::from($this->provider), $this->embeddingModel)
            ->fromArray([$text])
            ->asEmbeddings();

        return $response->embeddings[0]->embedding;
    }



    /**
     * Log an action
     */
    private function logAction(string $message, array $data = []): void
    {
        $this->actions[] = array_merge(['message' => $message], $data);
    }

    /**
     * Get all logged actions
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * Reset actions log
     */
    public function resetActions(): void
    {
        $this->actions = [];
    }

    /**
     * Add multiple propositions to chunks
     */
    public function addPropositions(array $propositions): void
    {
        foreach ($propositions as $proposition) {
            $this->addProposition($proposition);
            usleep(500000); // 0.5 second delay for API rate limiting
        }
    }

    /**
     * Add a single proposition to appropriate chunk
     */
    public function addProposition(string $proposition): void
    {
        $this->logAction("Adding proposition", ['proposition' => $proposition]);

        if (empty($this->chunks)) {
            $this->logAction("No chunks exist, creating new chunk");
            $this->createNewChunk($proposition);
            return;
        }

        $relevantChunkId = $this->findRelevantChunk($proposition);

        if ($relevantChunkId !== null) {
            $chunk = $this->chunks[$relevantChunkId];
            $this->logAction("Chunk found", [
                'chunk_id' => $chunk['chunk_id'],
                'title' => $chunk['title']
            ]);
            $this->addPropositionToChunk($relevantChunkId, $proposition);
            return;
        }

        $this->logAction("No matching chunk found, creating new chunk");
        $this->createNewChunk($proposition);
    }

    /**
     * Add proposition to existing chunk and update metadata
     */
    private function addPropositionToChunk(string $chunkId, string $proposition): void
    {
        $this->chunks[$chunkId]['propositions'][] = $proposition;
        $this->chunks[$chunkId]['summary'] = $this->updateChunkSummary($this->chunks[$chunkId]);
        $this->chunks[$chunkId]['title'] = $this->updateChunkTitle($this->chunks[$chunkId]);

        // Regenerate embedding for updated chunk
        $chunkText = implode("\n\n", $this->chunks[$chunkId]['propositions']);
        $this->chunks[$chunkId]['embedding'] = $this->generateEmbedding($chunkText);

        $this->logAction("Updated chunk metadata and embedding", [
            'chunk_id' => $chunkId,
            'new_title' => $this->chunks[$chunkId]['title']
        ]);
    }

    /**
     * Update chunk summary with new proposition
     */
    private function updateChunkSummary(array $chunk): string
    {
        $propositionsText = implode("\n", $chunk['propositions']);

        $prompt = <<<PROMPT
You are the steward of a group of chunks which represent groups of sentences that talk about a similar topic.
A new proposition was just added to one of your chunks, you should generate a very brief 1-sentence summary which will inform viewers what a chunk group is about.

A good summary will say what the chunk is about, and give any clarifying instructions on what to add to the chunk.

You will be given a group of propositions which are in the chunk and the chunks current summary.

Your summaries should anticipate generalization. If you get a proposition about apples, generalize it to food.
Or month, generalize it to "date and times".

Example:
Input: Proposition: Greg likes to eat pizza
Output: This chunk contains information about the types of food Greg likes to eat.

Only respond with the chunk new summary, nothing else.

Chunk's propositions:
{$propositionsText}

Current chunk summary:
{$chunk['summary']}
PROMPT;

        return trim($this->generateText($prompt));
    }

    /**
     * Update chunk title with new proposition
     */
    private function updateChunkTitle(array $chunk): string
    {
        $propositionsText = implode("\n", $chunk['propositions']);

        $prompt = <<<PROMPT
You are the steward of a group of chunks which represent groups of sentences that talk about a similar topic.
A new proposition was just added to one of your chunks, you should generate a very brief updated chunk title which will inform viewers what a chunk group is about.

A good title will say what the chunk is about.

You will be given a group of propositions which are in the chunk, chunk summary and the chunk title.

Your title should anticipate generalization. If you get a proposition about apples, generalize it to food.
Or month, generalize it to "date and times".

Example:
Input: Summary: This chunk is about dates and times that the author talks about
Output: Date & Times

Only respond with the new chunk title, nothing else.

Chunk's propositions:
{$propositionsText}

Chunk summary:
{$chunk['summary']}

Current chunk title:
{$chunk['title']}
PROMPT;

        return trim($this->generateText($prompt));
    }

    /**
     * Generate summary for new chunk
     */
    private function getNewChunkSummary(string $proposition): string
    {
        $prompt = <<<PROMPT
You are the steward of a group of chunks which represent groups of sentences that talk about a similar topic.
You should generate a very brief 1-sentence summary which will inform viewers what a chunk group is about.

A good summary will say what the chunk is about, and give any clarifying instructions on what to add to the chunk.

You will be given a proposition which will go into a new chunk. This new chunk needs a summary.

Your summaries should anticipate generalization. If you get a proposition about apples, generalize it to food.
Or month, generalize it to "date and times".

Example:
Input: Proposition: Greg likes to eat pizza
Output: This chunk contains information about the types of food Greg likes to eat.

Only respond with the new chunk summary, nothing else.

Determine the summary of the new chunk that this proposition will go into:
{$proposition}
PROMPT;

        return trim($this->generateText($prompt));
    }

    /**
     * Generate title for new chunk
     */
    private function getNewChunkTitle(string $summary): string
    {
        $prompt = <<<PROMPT
You are the steward of a group of chunks which represent groups of sentences that talk about a similar topic.
You should generate a very brief few word chunk title which will inform viewers what a chunk group is about.

A good chunk title is brief but encompasses what the chunk is about.

You will be given a summary of a chunk which needs a title.

Your titles should anticipate generalization. If you get a proposition about apples, generalize it to food.
Or month, generalize it to "date and times".

Example:
Input: Summary: This chunk is about dates and times that the author talks about
Output: Date & Times

Only respond with the new chunk title, nothing else.

Determine the title of the chunk that this summary belongs to:
{$summary}
PROMPT;

        return trim($this->generateText($prompt));
    }

    /**
     * Create a new chunk with proposition
     */
    private function createNewChunk(string $proposition): void
    {
        $newChunkId = substr(Str::uuid()->toString(), 0, $this->chunkIdLength);
        $newChunkSummary = $this->getNewChunkSummary($proposition);
        $newChunkTitle = $this->getNewChunkTitle($newChunkSummary);

        // Generate embedding for the chunk
        $embedding = $this->generateEmbedding($proposition);

        $this->chunks[$newChunkId] = [
            'chunk_id' => $newChunkId,
            'propositions' => [$proposition],
            'title' => $newChunkTitle,
            'summary' => $newChunkSummary,
            'chunk_index' => count($this->chunks),
            'embedding' => $embedding
        ];

        $this->logAction("Created new chunk with embedding", [
            'chunk_id' => $newChunkId,
            'title' => $newChunkTitle,
            'embedding_dimensions' => count($embedding)
        ]);
    }

    /**
     * Get outline of all chunks
     */
    private function getChunkOutline(): string
    {
        $outline = "";

        foreach ($this->chunks as $chunk) {
            $outline .= "Chunk ID: {$chunk['chunk_id']}\n";
            $outline .= "Chunk Name: {$chunk['title']}\n";
            $outline .= "Chunk Summary: {$chunk['summary']}\n\n";
        }

        return $outline;
    }

    /**
     * Find relevant chunk for proposition
     */
    private function findRelevantChunk(string $proposition): ?string
    {
        $outline = $this->getChunkOutline();

        $prompt = <<<PROMPT
Determine whether or not the "Proposition" should belong to any of the existing chunks.

A proposition should belong to a chunk if their meaning, direction, or intention are similar.
The goal is to group similar propositions and chunks.

If you think a proposition should be joined with a chunk, return the chunk id.
If you do not think an item should be joined with an existing chunk, just return "No chunks"

Example:
Input:
    - Proposition: "Greg really likes hamburgers"
    - Current Chunks:
        - Chunk ID: 2n4l3d
        - Chunk Name: Places in San Francisco
        - Chunk Summary: Overview of the things to do with San Francisco Places

        - Chunk ID: 93833k
        - Chunk Name: Food Greg likes
        - Chunk Summary: Lists of the food and dishes that Greg likes
Output: 93833k

Current Chunks:
--Start of current chunks--
{$outline}
--End of current chunks--

Determine if the following statement should belong to one of the chunks outlined:
{$proposition}
PROMPT;

        $chunkId = trim($this->generateText($prompt));

        if (strlen($chunkId) !== $this->chunkIdLength) {
            return null;
        }

        return $chunkId;
    }

    /**
     * Generate text using LLM
     */
    private function generateText(string $prompt): string
    {
        $response = Prism::text()
            ->using(Provider::from($this->provider), $this->model)
            ->withPrompt($prompt)
            ->asText();

        return $response->text;
    }

    /**
     * Get all chunks
     */
    public function getChunks(): array
    {
        return array_values($this->chunks);
    }

    /**
     * Reset chunks (for new documents)
     */
    public function resetChunks(): void
    {
        $this->chunks = [];
        $this->resetActions();
    }

    /**
     * Pretty print chunks for debugging
     */
    public function prettyPrintChunks(): array
    {
        $output = [];

        foreach ($this->chunks as $chunk) {
            $output[] = [
                'chunk_id' => $chunk['chunk_id'],
                'title' => trim($chunk['title']),
                'summary' => trim($chunk['summary']),
                'propositions' => $chunk['propositions'],
                'proposition_count' => count($chunk['propositions']),
                'embedding' => $chunk['embedding'],
                'embedding_dimensions' => count($chunk['embedding'])
            ];
        }

        return $output;
    }

    /**
     * Extract propositions from text using LLM
     */
    public function extractPropositions(string $text): array
    {
        $prompt = <<<PROMPT
Decompose the "Content" into clear and simple propositions, ensuring they are interpretable out of context.

1. Split compound sentences into simple sentences. Maintain the original phrasing from the input whenever possible.
2. For any named entity that is accompanied by additional descriptive information, separate this information into its own distinct proposition.
3. Decontextualize the proposition by adding necessary modifiers to nouns or entire sentences and replacing pronouns (e.g., "it", "he", "she", "they", "this", "that") with the full name of the entities they refer to.
4. Present the results as a JSON array of strings.

Example:
Input: "The earliest evidence for the Easter Hare was recorded in south-west Germany in 1678 by Georg Franck von Franckenau. He was a professor of medicine."

Output: ["The earliest evidence for the Easter Hare was recorded in south-west Germany in 1678 by Georg Franck von Franckenau.", "Georg Franck von Franckenau was a professor of medicine."]

Only respond with a valid JSON array, nothing else.

Decompose the following content:
{$text}
PROMPT;

        $response = trim($this->generateText($prompt));

        // Try to extract JSON from response
        if (preg_match('/\[.*\]/s', $response, $matches)) {
            $response = $matches[0];
        }

        try {
            return json_decode($response, true) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Process entire document with proposition extraction and chunking
     */
    public function processDocument(string $text): array
    {
        // Reset chunks for new document
        $this->resetChunks();

        // Split into paragraphs
        $paragraphs = array_filter(
            array_map('trim', explode("\n\n", $text)),
            fn($p) => !empty($p)
        );

        $allPropositions = [];

        foreach ($paragraphs as $i => $paragraph) {
            $this->logAction("Processing paragraph", ['paragraph_number' => $i + 1]);
            $propositions = $this->extractPropositions($paragraph);
            $allPropositions = array_merge($allPropositions, $propositions);
            usleep(500000); // Rate limiting
        }

        $this->logAction("Proposition extraction complete", [
            'total_propositions' => count($allPropositions)
        ]);

        // Now chunk the propositions
        $this->addPropositions($allPropositions);

        return $this->prettyPrintChunks();
    }

    /**
     * Process PDF file and return chunks
     */
    public function processPdfFile($file): array
    {
        $this->resetChunks();

        $extractedPages = $this->extractPdfText($file);

        if (empty($extractedPages)) {
            throw new \Exception('No text could be extracted from the PDF');
        }

        $allPropositions = [];

        // Extract propositions from each page
        foreach ($extractedPages as $pageData) {
            $this->logAction("Processing PDF page", [
                'page_number' => $pageData['page_number'],
                'extraction_method' => $pageData['extraction_method']
            ]);

            $propositions = $this->extractPropositions($pageData['text']);

            // Add page metadata to each proposition
            foreach ($propositions as &$prop) {
                $allPropositions[] = [
                    'text' => $prop,
                    'page_number' => $pageData['page_number'],
                    'extraction_method' => $pageData['extraction_method']
                ];
            }

            usleep(500000); // Rate limiting
        }

        $this->logAction("PDF proposition extraction complete", [
            'total_propositions' => count($allPropositions),
            'total_pages' => count($extractedPages)
        ]);

        // Add propositions to chunks
        foreach ($allPropositions as $propData) {
            $this->addProposition($propData['text']);
        }

        // Add page metadata to chunks
        $chunks = $this->prettyPrintChunks();

        foreach ($chunks as &$chunk) {
            $chunk['metadata'] = [
                'source' => $file->getClientOriginalName(),
                'type' => 'pdf',
                'extraction_method' => $extractedPages[0]['extraction_method'] ?? 'unknown',
                'total_pages' => count($extractedPages)
            ];
        }

        return $chunks;
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
