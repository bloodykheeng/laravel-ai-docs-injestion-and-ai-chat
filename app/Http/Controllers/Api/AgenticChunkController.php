<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AgenticChunkerService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AgenticChunkController extends Controller
{
    public function __construct(
        private AgenticChunkerService $agenticChunker
    ) {}

    /**
     * Chunk text using agentic chunking
     */
    public function chunk(Request $request)
    {
        $request->validate([
            'text' => 'required|string',
            'propositions' => 'sometimes|array',
            'propositions.*' => 'string'
        ]);

        try {
            if ($request->has('propositions')) {
                $this->agenticChunker->addPropositions($request->input('propositions'));
            } else {
                $text = $request->input('text');
                $this->agenticChunker->processDocument($text);
            }

            $chunks = $this->agenticChunker->prettyPrintChunks();
            $actions = $this->agenticChunker->getActions();

            return response()->json([
                'success' => true,
                'chunks' => $chunks,
                'total_chunks' => count($chunks),
                'actions' => $actions,
                'message' => 'Agentic chunking completed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Chunk PDF using agentic chunking with Tesseract OCR by default
     */

    public function chunkPdf(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:10240',
            'extraction_method' => 'sometimes|in:tesseract,spatie,smalot'
        ]);

        DB::beginTransaction();

        try {
            $file = $request->file('pdf');

            if ($request->has('extraction_method')) {
                $this->agenticChunker->setExtractionMethod($request->input('extraction_method'));
            }

            $filename = time() . '_' . $file->getClientOriginalName();
            $filePath = $file->storeAs('documents/pdfs', $filename, 'public');

            $chunks = $this->agenticChunker->processPdfFile($file);

            if (empty($chunks)) {
                throw new \Exception('No chunks were generated from the PDF');
            }

            $actions = $this->agenticChunker->getActions();

            $document = Document::create([
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'extraction_method' => $chunks[0]['metadata']['extraction_method'] ?? 'unknown',
                'chunking_strategy' => 'Agentic Splitting',
                'total_pages' => $chunks[0]['metadata']['total_pages'] ?? 0,
                'total_chunks' => count($chunks),
                'metadata' => [
                    'uploaded_at' => now()->toDateTimeString(),
                    'user_agent' => $request->userAgent(),
                    'actions' => $actions,
                ]
            ]);

            $chunkRecords = [];
            foreach ($chunks as $index => $chunk) {
                // Convert propositions array to text for page_content
                $pageContent = "Title: {$chunk['title']}\n\nSummary: {$chunk['summary']}\n\nPropositions:\n"
                    . implode("\n", $chunk['propositions']);

                $chunkRecords[] = [
                    'document_id' => $document->id,
                    'page_content' => $pageContent,
                    'embedding' => json_encode($chunk['embedding']),
                    'page_number' => null, // Agentic chunks don't have specific pages
                    'extraction_method' => $chunk['metadata']['extraction_method'] ?? null,
                    'chunking_strategy' => 'Agentic Splitting',
                    'similarity_score' => null,
                    'token_count' => $chunk['proposition_count'] ?? 0,
                    'paragraph_count' => count($chunk['propositions']) ?? 0,
                    'chunk_index' => $index,
                    'metadata' => json_encode([
                        'chunk_id' => $chunk['chunk_id'] ?? null,
                        'title' => $chunk['title'] ?? null,
                        'summary' => $chunk['summary'] ?? null,
                        'propositions' => $chunk['propositions'] ?? [],
                        'proposition_count' => $chunk['proposition_count'] ?? 0,
                        'embedding_dimensions' => $chunk['embedding_dimensions'] ?? 768,
                        'source' => $chunk['metadata']['source'] ?? null,
                        'type' => $chunk['metadata']['type'] ?? 'pdf',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DocumentChunk::insert($chunkRecords);

            DB::commit();

            $firstChunkPreview = !empty($chunks[0]['propositions'])
                ? implode(', ', array_slice($chunks[0]['propositions'], 0, 3))
                : '';

            return response()->json([
                'success' => true,
                'document' => [
                    'id' => $document->id,
                    'filename' => $document->original_filename,
                    'file_size' => $document->file_size_human,
                    'chunking_strategy' => $document->chunking_strategy,
                    'total_pages' => $document->total_pages,
                    'total_chunks' => $document->total_chunks,
                    'extraction_method' => $document->extraction_method,
                ],
                'chunks_preview' => [
                    'first_chunk' => $firstChunkPreview,
                    'sample_metadata' => $chunks[0]['metadata'] ?? [],
                ],
                'statistics' => [
                    'total_chunks' => count($chunks),
                    'total_pages' => $chunks[0]['metadata']['total_pages'] ?? 0,
                    'avg_propositions_per_chunk' => round(collect($chunks)->avg(fn($c) => $c['proposition_count'] ?? 0)),
                ],
                'actions' => $actions,
                'message' => 'PDF processed and chunked using agentic chunking'
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();

            if (isset($filePath)) {
                Storage::disk('public')->delete($filePath);
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test agentic chunking with sample data
     */
    public function testAgentic()
    {
        try {
            $testPropositions = [
                "Greg likes to eat pizza",
                "Sarah enjoys Italian food",
                "The Eiffel Tower is in Paris",
                "Paris is the capital of France",
                "Greg also loves hamburgers",
                "The weather in Paris is often rainy"
            ];

            $this->agenticChunker->resetChunks();
            $this->agenticChunker->addPropositions($testPropositions);
            $chunks = $this->agenticChunker->prettyPrintChunks();
            $actions = $this->agenticChunker->getActions();

            return response()->json([
                'success' => true,
                'chunks' => $chunks,
                'total_chunks' => count($chunks),
                'actions' => $actions,
                'message' => 'Using TRUE agentic chunking with LLM-based proposition grouping!',
                'test_propositions' => $testPropositions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
