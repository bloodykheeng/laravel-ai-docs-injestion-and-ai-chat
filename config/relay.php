<?php

declare(strict_types=1);

use Prism\Relay\Enums\Transport;

return [
    /*
    |--------------------------------------------------------------------------
    | MCP Server Configurations
    |--------------------------------------------------------------------------
    |
    | Define your MCP server configurations here. Each server should have a
    | name as the key, and a configuration array with the appropriate settings.
    |
    */
    'servers' => [
        'puppeteer' => [
            'transport' => Transport::Stdio,
            'command' => ['npx', '-y', '@modelcontextprotocol/server-puppeteer'],
            'timeout' => env('RELAY_PUPPETEER_SERVER_TIMEOUT', 60),
            'env' => [],
        ],
        'documents' => [
            'url' => env('RELAY_DOCUMENTS_URL', 'http://127.0.0.1:8000/mcp/documents'),
            'timeout' => 30,
            'transport' => \Prism\Relay\Enums\Transport::Http,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Definition Cache Duration
    |--------------------------------------------------------------------------
    |
    | This value determines how long (in minutes) the tool definitions fetched
    | from MCP servers will be cached. Set to 0 to disable caching entirely.
    |
    */
    'cache_duration' => env('RELAY_TOOLS_CACHE_DURATION', 60),
];
