<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\SemanticChunkerService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SemanticChunkController extends Controller
{
    public function __construct(
        private SemanticChunkerService $semanticChunker
    ) {}

    public function chunk(Request $request)
    {
        $request->validate([
            'text' => 'required|string'
        ]);

        $text = $request->input('text');

        $document = [
            'page_content' => $text,
            'metadata' => ['source' => 'api']
        ];

        $chunks = $this->semanticChunker->splitDocuments([$document]);

        return response()->json([
            'chunks' => $chunks,
            'total' => count($chunks)
        ]);
    }

    public function chunkPdf(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:10240',
            'extraction_method' => 'sometimes|in:tesseract,spatie,smalot'
        ]);

        DB::beginTransaction();

        try {
            $file = $request->file('pdf');

            // Set extraction method if provided
            if ($request->has('extraction_method')) {
                $this->semanticChunker->setExtractionMethod($request->input('extraction_method'));
            }

            // Store the PDF file
            $filename = time() . '_' . $file->getClientOriginalName();
            $filePath = $file->storeAs('documents/pdfs', $filename, 'public');

            // Use the service method to handle all PDF extraction and chunking
            $chunks = $this->semanticChunker->processPdfFile($file);

            if (empty($chunks)) {
                throw new \Exception('No chunks were generated from the PDF');
            }

            // Create document record
            $document = Document::create([
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'extraction_method' => $chunks[0]['metadata']['extraction_method'] ?? 'unknown',
                'chunking_strategy' => 'Semantic Splitting',
                'total_pages' => $this->semanticChunker->countUniquePages($chunks),
                'total_chunks' => count($chunks),
                'metadata' => [
                    'uploaded_at' => now()->toDateTimeString(),
                    'user_agent' => $request->userAgent(),
                ]
            ]);

            // Create chunk records
            $chunkRecords = [];
            foreach ($chunks as $index => $chunk) {
                $chunkRecords[] = [
                    'document_id' => $document->id,
                    'page_content' => $chunk['page_content'],
                    'embedding' => json_encode($chunk['embedding']), // pgvector will handle this
                    'page_number' => $chunk['metadata']['page_number'] ?? null,
                    'extraction_method' => $chunk['metadata']['extraction_method'] ?? null,
                    'chunking_strategy' => 'Semantic Splitting',
                    'similarity_score' => $chunk['metadata']['similarity_score'] ?? null,
                    'token_count' => $chunk['metadata']['token_count'] ?? 0,
                    'paragraph_count' => $chunk['metadata']['paragraph_count'] ?? 0,
                    'chunk_index' => $index,
                    'metadata' => json_encode([
                        'source' => $chunk['metadata']['source'] ?? null,
                        'type' => $chunk['metadata']['type'] ?? 'pdf',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Batch insert chunks for better performance
            DocumentChunk::insert($chunkRecords);

            DB::commit();

            // Get a preview of the first chunk's text
            $textPreview = substr($chunks[0]['page_content'], 0, 200);

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
                    'first_chunk' => $textPreview,
                    'sample_metadata' => $chunks[0]['metadata'] ?? [],
                ],
                'statistics' => [
                    'total_chunks' => count($chunks),
                    'pages_processed' => $this->semanticChunker->countUniquePages($chunks),
                    'avg_tokens_per_chunk' => round(collect($chunks)->avg(fn($c) => $c['metadata']['token_count'] ?? 0)),
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            // Clean up uploaded file if it exists
            if (isset($filePath)) {
                Storage::disk('public')->delete($filePath);
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function testSemantic()
    {
        $text = "The cat sat on the mat. It was a sunny day. The dog played in the yard. Birds were singing in the trees. Spring had arrived at last.";

        $document = [
            'page_content' => $text,
            'metadata' => ['source' => 'test']
        ];

        $chunks = $this->semanticChunker->splitDocuments([$document]);

        return response()->json([
            'chunks' => $chunks,
            'total' => count($chunks),
            'message' => 'Using TRUE semantic chunking with embeddings and cosine similarity!',
            'includes_embeddings' => !empty($chunks) && isset($chunks[0]['embedding'])
        ]);
    }
}
