<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\SendSmsNotificationTool;
use Laravel\Mcp\Server;

class SmsNotificationServer extends Server
{

    // this is a manual overide of supported version ive added this myself
    protected array $supportedProtocolVersion = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    /**
     * The MCP server's name.
     */
    protected string $name = 'SMS Notification Server';

    /**
     * The MCP server's version.
     */
    protected string $version = '1.0.0';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'MARKDOWN'
        This server sends SMS notifications to users in Uganda.

        **IMPORTANT PHONE NUMBER REQUIREMENTS:**
        - All phone numbers MUST start with 256 (Uganda country code)
        - Phone numbers MUST be exactly 12 digits total (256 + 9 digits)
        - Format: 256XXXXXXXXX (e.g., 256701234567, 256771234567)

        **CRITICAL: If the user provides a phone number that does NOT start with 256 or is not 12 digits:**
        - ALWAYS prompt the user to provide a valid Ugandan phone number
        - Explain the correct format: "Please provide a valid Ugandan phone number starting with 256 and containing 12 digits total (e.g., 256701234567)"
        - Do NOT attempt to send SMS until a valid phone number is provided

        You can send to:
        - Single recipient: One phone number
        - Multiple recipients: Comma-separated phone numbers (all must be valid)

        Optional parameters:
        - sender: Custom sender ID
        - sendtime: Schedule SMS for future delivery (format: YYYY-MM-DD HH:MM:SS)
    MARKDOWN;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        SendSmsNotificationTool::class,
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
