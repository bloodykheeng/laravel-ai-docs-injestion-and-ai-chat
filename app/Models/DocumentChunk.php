<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;


class DocumentChunk extends Model
{
    use HasFactory, HasNeighbors;

    protected $fillable = [
        'document_id',
        'page_content',
        'embedding',
        'page_number',
        'extraction_method',
        'chunking_strategy',
        'similarity_score',
        'token_count',
        'paragraph_count',
        'chunk_index',
        'metadata',
    ];

    protected $casts = [
        'embedding' => Vector::class,
        'metadata' => 'array',
        'page_number' => 'integer',
        'token_count' => 'integer',
        'paragraph_count' => 'integer',
        'chunk_index' => 'integer',
        'similarity_score' => 'float',
    ];

    /**
     * Get the document this chunk belongs to
     */
    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get a preview of the content (first 200 chars)
     */
    public function getContentPreviewAttribute(): string
    {
        return substr($this->page_content, 0, 200) . (strlen($this->page_content) > 200 ? '...' : '');
    }
}
