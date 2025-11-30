<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentChunk;
use Exception;
use Illuminate\Http\Request;
use Pgvector\Laravel\Distance;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

class ChatController extends Controller
{
    private string $provider = 'ollama';
    private string $embeddingModel = 'embeddinggemma';
    // private string $model = 'gpt-oss:120b-cloud';
    private string $model = 'qwen3-coder:480b-cloud';


    public function askAboutDocs(Request $request)
    {
        $request->validate([
            'question' => 'required|string',
            'document_name' => 'sometimes|string',
            'chunking_strategy' => 'sometimes|string|in:Character,Recursive Character,Document Specific,Semantic Splitting,Agentic Splitting'
        ]);

        try {
            $question = $request->input('question');
            $documentName = $request->input('document_name');
            $chunkingStrategy = $request->input('chunking_strategy');

            // Generate embedding for the question
            $questionEmbedding = $this->generateEmbedding($question);

            // Build query for similar chunks
            $query = DocumentChunk::query()
                ->nearestNeighbors('embedding', $questionEmbedding,  Distance::Cosine)
                ->with('document:id,original_filename');

            // Filter by document if specified
            if ($documentName) {
                $query->whereHas('document', function ($q) use ($documentName) {
                    $q->where('original_filename', 'like', "%{$documentName}%");
                });
            }

            // Filter by chunking strategy if specified
            if ($chunkingStrategy) {
                $query->where('chunking_strategy', $chunkingStrategy);
            }

            $relevantChunks = $query->limit(5)->get();

            if ($relevantChunks->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'error' => 'No relevant information found in documents'
                ], 404);
            }

            // Build context from chunks
            $context = $relevantChunks->map(function ($chunk) {
                return "Document: {$chunk->document->original_filename}\nPage: {$chunk->page_number}\n{$chunk->page_content}";
            })->join("\n\n---\n\n");

            // Ask AI using Prism
            $prompt = "Based on the following context, answer the question.\n\nContext:\n{$context}\n\nQuestion: {$question}\n\nAnswer:";

            $aiResponse = $this->generateText($prompt);

            return response()->json([
                'success' => true,
                'question' => $question,
                'answer' => $aiResponse,
                'sources' => $relevantChunks->map(function ($chunk) {
                    return [
                        'document' => $chunk->document->original_filename,
                        'page' => $chunk->page_number,
                        'chunking_strategy' => $chunk->chunking_strategy,
                        'preview' => substr($chunk->page_content, 0, 150) . '...'
                    ];
                })
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function generateEmbedding(string $text): array
    {
        $response = Prism::embeddings()
            ->using(Provider::from($this->provider), $this->embeddingModel)
            ->fromArray([$text])
            ->asEmbeddings();

        return $response->embeddings[0]->embedding;
    }

    private function generateText(string $prompt): string
    {
        $response = Prism::text()
            ->using(Provider::from($this->provider), $this->model)
            ->withPrompt($prompt)
            ->asText();

        return $response->text;
    }
}
