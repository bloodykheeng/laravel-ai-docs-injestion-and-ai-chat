<?php

use App\Http\Controllers\Api\AgenticChunkController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ChunkController;
use App\Http\Controllers\Api\SemanticChunkController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;





Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Agentic Chunking Routes
// Agentic Chunking Routes
Route::prefix('agentic')->group(function () {
    Route::post('/chunk', [AgenticChunkController::class, 'chunk']);
    Route::post('/chunk-pdf', [AgenticChunkController::class, 'chunkPdf']);
    Route::get('/test', [AgenticChunkController::class, 'testAgentic']);
});

// Semantic Chunking Routes
Route::post('/semantic/chunk', [SemanticChunkController::class, 'chunk']);
Route::post('/semantic/chunk-pdf', [SemanticChunkController::class, 'chunkPdf']);
Route::get('/semantic/test', [SemanticChunkController::class, 'testSemantic']);

Route::post('/chat/ask', [ChatController::class, 'askAboutDocs']);
