<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Sms;

use App\Modules\Integration\Contracts\SmsProviderContract;
use App\Modules\Integration\Data\SmsMessage;
use App\Modules\Integration\Data\SmsResult;
use App\Modules\Integration\Models\IntegrationProvider;
use Illuminate\Support\Facades\Http;

/**
 * Twilio, over the HTTP client rather than the vendor SDK.
 *
 * Written against our own contract and Laravel's HTTP client so the driver adds no
 * dependency, stays faithful to Twilio's actual request and error shape, and remains
 * exercisable in tests through Http::fake().
 */
class TwilioSmsProvider implements SmsProviderContract
{
    private const BASE_URL = 'https://api.twilio.com/2010-04-01';

    private const TIMEOUT_SECONDS = 10;

    public function driver(): string
    {
        return 'twilio';
    }

    public function send(SmsMessage $message, IntegrationProvider $provider): SmsResult
    {
        $credentials = $provider->getCredentials();
        $accountSid = (string) ($credentials['account_sid'] ?? '');
        $authToken = (string) ($credentials['auth_token'] ?? '');
        $from = (string) ($provider->settings['from'] ?? '');

        if ($accountSid === '' || $authToken === '' || $from === '') {
            return SmsResult::failure(
                $this->driver(),
                'MISCONFIGURED',
                'The Twilio provider needs account_sid and auth_token credentials and a from number.'
            );
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($accountSid, $authToken)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::BASE_URL.'/Accounts/'.$accountSid.'/Messages.json', [
                    'To' => $message->to,
                    'From' => $from,
                    'Body' => $message->body,
                ]);
        } catch (\Throwable $e) {
            // A transport failure is a normal reason to fall back, not a fault in us.
            return SmsResult::failure($this->driver(), 'TRANSPORT_ERROR', $e->getMessage());
        }

        if ($response->successful()) {
            return SmsResult::success($this->driver(), (string) $response->json('sid'));
        }

        return SmsResult::failure(
            $this->driver(),
            (string) ($response->json('code') ?? $response->status()),
            (string) ($response->json('message') ?? 'The Twilio request failed.')
        );
    }
}
