<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'filename',
        'original_filename',
        'file_path',
        'mime_type',
        'file_size',
        'extraction_method',
        'chunking_strategy',
        'total_pages',
        'total_chunks',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'file_size' => 'integer',
        'total_pages' => 'integer',
        'total_chunks' => 'integer',
    ];

    /**
     * Get all chunks for this document
     */
    public function chunks()
    {
        return $this->hasMany(DocumentChunk::class);
    }

    /**
     * Get file size in human-readable format
     */
    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
