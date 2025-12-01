<?php

namespace App\Traits;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

trait SendsSmsNotification
{
    // use Loggable;

    /**
     * Normalize phone number by removing any leading plus sign.
     *
     * @param string $number
     * @return string
     */
    protected function normalizePhoneNumber(string $number): string
    {
        return ltrim($number, '+');
    }

    /**
     * Send SMS Notification.
     *
     * @param string $recipientType single | multiple
     * @param string|array $recipient
     * @param string $message
     * @param string|null $sender
     * @param string|null $sendtime
     * @param User|null $createdBy
     * @return bool
     */
    public function sendSmsNotification(
        string $recipientType,
        string|array $recipient,
        string $message,
        ?string $sender = null,
        ?string $sendtime = null,
        ?User $createdBy = null
    ): bool {
        try {
            // Normalize recipient(s)
            if ($recipientType === 'multiple' && is_array($recipient)) {
                $recipient = array_map(fn($num) => $this->normalizePhoneNumber(trim($num)), $recipient);
                $recipient = implode(',', $recipient);
            } elseif ($recipientType === 'single' && is_string($recipient)) {
                $recipient = $this->normalizePhoneNumber(trim($recipient));
            } else {
                throw new \Exception("Invalid recipient format, multiple must be array & single must be string.");
            }
            $creator = $createdBy ?? Auth::user();

            // Build query parameters
            $queryParams = [
                'message'       => $message,
                'recipient'     => $recipient,
                'account'       => env('SMS_ACCOUNT_ID'),
                'authorization' => env('SMS_AUTH_CODE'),
            ];

            if ($sender) {
                $queryParams['sender'] = $sender;
            }

            if ($sendtime) {
                $queryParams['sendtime'] = $sendtime;
            }

            $response = Http::get(env('SMS_API_URL'), $queryParams);

            // // Log activity
            // $this->logActivity(
            //     'sms',
            //     "SMS sent to {$recipient}" . ($creator ? " by {$creator->name}" : ""),
            //     [
            //         'message'   => $message,
            //         'recipient' => $recipient,
            //         'response'  => $response->body(),
            //     ],
            //     $creator
            // );

            return $response->successful();

            // return true;
        } catch (Exception $e) {
            // $this->logActivity(
            //     'sms_error',
            //     "Failed to send SMS to {$recipient}. Error: {$e->getMessage()}",
            //     [
            //         'error' => $e->getMessage(),
            //         'recipient' => $recipient,
            //         'message'   => $message,
            //     ],
            //     $createdBy ?? Auth::user()
            // );

            return false;
        }
    }
}
