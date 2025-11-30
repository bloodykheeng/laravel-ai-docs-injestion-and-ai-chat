<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetDocumentStatsTool;
use Laravel\Mcp\Server;

class DocumentStatsServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'Document Stats Server';

    /**
     * The MCP server's version.
     */
    protected string $version = '0.0.1';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'MARKDOWN'
        This server provides statistics and information about documents in the system.
        You can query specific documents by ID or filename, or get overall statistics.
    MARKDOWN;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        GetDocumentStatsTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [
        //
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    protected array $prompts = [
        //
    ];
}
