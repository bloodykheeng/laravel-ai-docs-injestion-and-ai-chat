<?php

namespace App\Mcp\Tools;

use App\Traits\SendsSmsNotification;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SendSmsNotificationTool extends Tool
{
    use SendsSmsNotification;

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Send SMS notifications to Ugandan phone numbers.

        **CRITICAL VALIDATION:**
        - Phone numbers MUST start with 256
        - Phone numbers MUST be exactly 12 digits
        - If validation fails, the tool will return an error asking the user to provide a valid number
    MARKDOWN;

    /**
     * Validate Ugandan phone number.
     *
     * @param string $phoneNumber
     * @return bool
     */
    protected function isValidUgandanNumber(string $phoneNumber): bool
    {
        // Remove any whitespace
        $phoneNumber = preg_replace('/\s+/', '', $phoneNumber);

        // Check if starts with 256 and is exactly 12 digits
        return preg_match('/^256\d{9}$/', $phoneNumber) === 1;
    }

    /**
     * Validate multiple phone numbers.
     *
     * @param array $phoneNumbers
     * @return array ['valid' => bool, 'invalid' => array]
     */
    protected function validateMultipleNumbers(array $phoneNumbers): array
    {
        $invalid = [];

        foreach ($phoneNumbers as $number) {
            $cleanNumber = preg_replace('/\s+/', '', trim($number));
            if (!$this->isValidUgandanNumber($cleanNumber)) {
                $invalid[] = $cleanNumber;
            }
        }

        return [
            'valid' => empty($invalid),
            'invalid' => $invalid,
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $recipientType = $request->get('recipient_type', 'single');
        $recipient = $request->get('recipient');
        $message = $request->get('message');
        $sender = $request->get('sender');
        $sendtime = $request->get('sendtime');

        // Validate message
        if (empty($message)) {
            return Response::error('Message is required and cannot be empty.');
        }

        // Validate recipient based on type
        if ($recipientType === 'single') {
            if (!is_string($recipient)) {
                return Response::error('For single recipient, phone number must be a string.');
            }

            $cleanRecipient = preg_replace('/\s+/', '', trim($recipient));

            if (!$this->isValidUgandanNumber($cleanRecipient)) {
                return Response::error(
                    "Invalid phone number format. Please provide a valid Ugandan phone number:\n" .
                        "- Must start with 256\n" .
                        "- Must be exactly 12 digits\n" .
                        "- Example: 256701234567 or 256771234567\n\n" .
                        "Your number: {$cleanRecipient}"
                );
            }

            $recipient = $cleanRecipient;
        } elseif ($recipientType === 'multiple') {
            if (!is_string($recipient)) {
                return Response::error('For multiple recipients, provide comma-separated phone numbers as a string.');
            }

            // Split by comma and validate each
            $numbers = array_map('trim', explode(',', $recipient));
            $validation = $this->validateMultipleNumbers($numbers);

            if (!$validation['valid']) {
                return Response::error(
                    "Invalid phone number(s) detected:\n" .
                        implode("\n", $validation['invalid']) . "\n\n" .
                        "All phone numbers must:\n" .
                        "- Start with 256\n" .
                        "- Be exactly 12 digits\n" .
                        "- Example: 256701234567, 256771234567"
                );
            }

            // Clean all numbers
            $recipient = array_map(function ($num) {
                return preg_replace('/\s+/', '', trim($num));
            }, $numbers);
        } else {
            return Response::error('Invalid recipient_type. Use "single" or "multiple".');
        }

        // Send SMS
        $success = $this->sendSmsNotification(
            $recipientType,
            $recipient,
            $message,
            $sender,
            $sendtime
        );

        if ($success) {
            $recipientDisplay = is_array($recipient) ? implode(', ', $recipient) : $recipient;
            return Response::text(json_encode([
                'success' => true,
                'message' => 'SMS sent successfully',
                'recipient' => $recipientDisplay,
                'recipient_type' => $recipientType,
                'scheduled' => $sendtime ? true : false,
            ], JSON_PRETTY_PRINT));
        }

        return Response::error('Failed to send SMS. Check logs for details.');
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'recipient_type' => $schema->string()
                ->description('Type of recipient: "single" for one number or "multiple" for comma-separated numbers')
                ->enum(['single', 'multiple'])
                ->required(),

            'recipient' => $schema->string()
                ->description('Phone number(s). MUST start with 256 and be 12 digits. For multiple: comma-separated (e.g., "256701234567,256771234567")')
                ->required(),

            'message' => $schema->string()
                ->description('The SMS message content to send')
                ->required(),

            'sender' => $schema->string()
                ->description('Optional: Custom sender ID'),

            'sendtime' => $schema->string()
                ->description('Optional: Schedule SMS for future delivery (format: YYYY-MM-DD HH:MM:SS)'),
        ];
    }
}
