<?php

use App\Mcp\Servers\DocumentStatsServer;
use Laravel\Mcp\Facades\Mcp;


// Mcp::web('/mcp/demo', \App\Mcp\Servers\PublicServer::class);


// Local server (for AI desktop clients)
Mcp::local('documents', DocumentStatsServer::class);

// Web server (for HTTP API)
Mcp::web('/mcp/documents', DocumentStatsServer::class);
