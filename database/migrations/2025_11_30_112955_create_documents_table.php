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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('mime_type')->default('application/pdf');
            $table->integer('file_size'); // in bytes
            $table->string('chunking_strategy')->nullable()->index(); //Character , Recursive Curracter, Document Specific,Semantic splitting , Agentic Splitting
            $table->string('extraction_method')->nullable();
            $table->integer('total_pages')->default(0);
            $table->integer('total_chunks')->default(0);
            $table->text('metadata')->nullable(); // JSON encoded metadata
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
