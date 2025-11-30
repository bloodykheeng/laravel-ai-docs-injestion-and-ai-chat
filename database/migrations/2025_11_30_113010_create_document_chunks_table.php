<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->text('page_content');
            $table->vector('embedding', dimensions: 768);
            $table->integer('page_number')->nullable();
            $table->string('extraction_method')->nullable();
            $table->string('chunking_strategy')->nullable()->index(); //Character , Recursive Curracter, Document Specific,Semantic splitting , Agentic Splitting
            $table->float('similarity_score')->nullable();
            $table->integer('token_count')->default(0);
            $table->integer('paragraph_count')->default(0);
            $table->integer('chunk_index')->default(0); // Order of chunks
            $table->text('metadata')->nullable(); // JSON encoded additional metadata
            $table->timestamps();

            // Index for faster similarity searches
            $table->index('document_id');
            $table->index('page_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
