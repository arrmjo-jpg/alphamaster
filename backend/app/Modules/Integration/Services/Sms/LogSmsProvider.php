<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Sms;

use App\Modules\Integration\Contracts\SmsProviderContract;
use App\Modules\Integration\Data\SmsMessage;
use App\Modules\Integration\Data\SmsResult;
use App\Modules\Integration\Models\IntegrationProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the message to the log instead of sending it.
 *
 * A genuinely useful provider for local development and for environments that must
 * not contact a vendor, not a placeholder. The recipient is redacted because a phone
 * number is personal data and logs are the least controlled store we have.
 */
class LogSmsProvider implements SmsProviderContract
{
    public function driver(): string
    {
        return 'log';
    }

    public function send(SmsMessage $message, IntegrationProvider $provider): SmsResult
    {
        Log::info('SMS dispatched via the log provider.', [
            'to' => $this->redact($message->to),
            'body_length' => mb_strlen($message->body),
            'provider_id' => $provider->id,
        ]);

        return SmsResult::success($this->driver(), 'log-'.Str::ulid());
    }

    /**
     * Keep only enough of the number to correlate a report with a delivery.
     */
    private function redact(string $number): string
    {
        $length = mb_strlen($number);

        return $length <= 4
            ? str_repeat('*', $length)
            : str_repeat('*', $length - 4).mb_substr($number, -4);
    }
}
