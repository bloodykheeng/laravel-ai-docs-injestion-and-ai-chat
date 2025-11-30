<?php

namespace App\Mcp\Tools;

use App\Models\Document;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetDocumentStatsTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Get document statistics. Provide document_id or filename for specific document, or leave empty for overall stats.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $docId = $request->get('document_id');
        $filename = $request->get('filename');

        // Specific document
        if ($docId || $filename) {
            $query = Document::with('chunks');

            if ($docId) {
                $doc = $query->find($docId);
            } else {
                $doc = $query->where('original_filename', 'like', "%{$filename}%")->first();
            }

            if (!$doc) {
                return Response::error('Document not found');
            }

            $stats = [
                'id' => $doc->id,
                'filename' => $doc->original_filename,
                'mime_type' => $doc->mime_type,
                'file_size' => $doc->file_size_human,
                'total_pages' => $doc->total_pages,
                'total_chunks' => $doc->total_chunks,
                'extraction_method' => $doc->extraction_method,
                'chunking_strategy' => $doc->chunking_strategy,
                'created_at' => $doc->created_at,
            ];

            return Response::text(json_encode($stats, JSON_PRETTY_PRINT));
        }

        // Overall stats
        $totalDocs = Document::count();
        $byMethod = Document::selectRaw('extraction_method, count(*) as count')
            ->groupBy('extraction_method')
            ->get()
            ->pluck('count', 'extraction_method');

        $byStrategy = Document::selectRaw('chunking_strategy, count(*) as count')
            ->groupBy('chunking_strategy')
            ->get()
            ->pluck('count', 'chunking_strategy');

        $byMimeType = Document::selectRaw('mime_type, count(*) as count')
            ->groupBy('mime_type')
            ->get()
            ->pluck('count', 'mime_type');

        $stats = [
            'total_documents' => $totalDocs,
            'by_extraction_method' => $byMethod,
            'by_chunking_strategy' => $byStrategy,
            'by_mime_type' => $byMimeType,
        ];

        return Response::text(json_encode($stats, JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('Optional: The document ID'),
            'filename' => $schema->string()
                ->description('Optional: Search by filename'),
        ];
    }
}
