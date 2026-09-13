<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Contracts\SmsDispatcherContract;
use App\Modules\Integration\Data\SmsMessage;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Integration\Services\ProviderHttp;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Retrying the same vendor (ADR 0047).
 *
 * The rule under test is narrow on purpose: a vendor is tried again only when the
 * connection never opened, because only then is it certain the vendor never saw the
 * request. A timeout and an answer — any answer, a 500 included — go to the failover
 * chain without a second attempt, since either can mean a message already queued.
 */
uses(RefreshDatabase::class);

const NEVER_RESOLVED = 'cURL error 6: Could not resolve host: vendor.example.test';

const REFUSED = 'cURL error 7: Failed to connect to vendor.example.test port 443: Connection refused';

const TIMED_OUT = 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    // The backoff is real time otherwise; what it would have waited is asserted.
    Sleep::fake();
});

function retriesSetTo(int $retries): void
{
    app(SettingServiceInterface::class)->set('operations', 'provider_retry_attempts', $retries);
}

/**
 * A vendor that plays a script, one step per request, repeating the last step. A string
 * step is a connection failure with that curl message; an integer is an HTTP answer
 * with that status. `calls` counts what actually reached it — the fake records no
 * request for a connection that failed, so the count is kept here.
 *
 * @param  list<string|int>  $steps
 */
function vendorScript(array $steps): object
{
    return new class($steps)
    {
        public int $calls = 0;

        /**
         * @param  list<string|int>  $steps
         */
        public function __construct(private readonly array $steps) {}

        public function __invoke(mixed $request): mixed
        {
            $step = $this->steps[min($this->calls, count($this->steps) - 1)];
            $this->calls++;

            return is_string($step)
                ? (Http::failedConnection($step))($request)
                : Http::response(['sid' => 'SM_retry_test', 'ok' => $step < 400], $step);
        }
    };
}

function fakeVendor(string $pattern, object $script): void
{
    Http::fake([$pattern => fn (mixed $request): mixed => $script($request)]);
}

/**
 * Twilio first in the SMS chain, with the log driver still active behind it.
 */
function twilioFirstInChain(): IntegrationProvider
{
    IntegrationProvider::query()->where('driver', 'log')->update(['is_default' => false]);

    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $twilio->setCredentials(['account_sid' => 'AC_retry_sid', 'auth_token' => 'retry_token']);
    $twilio->forceFill([
        'settings' => ['from' => '+15550000000'],
        'is_active' => true,
        'is_default' => true,
    ])->save();

    return $twilio->refresh();
}

// ── The rule ─────────────────────────────────────────────────────────────────

test('a host that never resolved is tried again, and the second attempt is the one that counts', function (): void {
    $vendor = vendorScript([NEVER_RESOLVED, 200]);
    fakeVendor('vendor.example.test/*', $vendor);

    $response = ProviderHttp::client()->get('https://vendor.example.test/ping');

    expect($response->successful())->toBeTrue()
        ->and($vendor->calls)->toBe(2);
    Sleep::assertSleptTimes(1);
});

test('a refused connection is tried again too', function (): void {
    $vendor = vendorScript([REFUSED, 200]);
    fakeVendor('vendor.example.test/*', $vendor);

    expect(ProviderHttp::client()->get('https://vendor.example.test/ping')->successful())->toBeTrue()
        ->and($vendor->calls)->toBe(2);
});

test('a timeout is never retried: the vendor may already be holding the request', function (): void {
    $vendor = vendorScript([TIMED_OUT, 200]);
    fakeVendor('vendor.example.test/*', $vendor);

    expect(fn () => ProviderHttp::client()->get('https://vendor.example.test/ping'))
        ->toThrow(ConnectionException::class);

    expect($vendor->calls)->toBe(1);
    Sleep::assertNeverSlept();
});

test('an answer from the vendor is never retried, even a 500', function (): void {
    $vendor = vendorScript([500, 200]);
    fakeVendor('vendor.example.test/*', $vendor);

    $response = ProviderHttp::client()->get('https://vendor.example.test/ping');

    // Returned to the driver as the answer it is, not thrown and not repeated.
    expect($response->status())->toBe(500)
        ->and($vendor->calls)->toBe(1);
});

test('attempts stop at the operator\'s number', function (int $retries): void {
    retriesSetTo($retries);

    $vendor = vendorScript([NEVER_RESOLVED]);
    fakeVendor('vendor.example.test/*', $vendor);

    expect(fn () => ProviderHttp::client()->get('https://vendor.example.test/ping'))
        ->toThrow(ConnectionException::class);

    expect($vendor->calls)->toBe($retries + 1);
    Sleep::assertSleptTimes($retries);
})->with([0, 1, 3]);

test('the wait doubles from a tenth of a second and stops growing at one second', function (): void {
    expect(ProviderHttp::backoffMilliseconds(1))->toBe(100)
        ->and(ProviderHttp::backoffMilliseconds(2))->toBe(200)
        ->and(ProviderHttp::backoffMilliseconds(4))->toBe(800)
        ->and(ProviderHttp::backoffMilliseconds(5))->toBe(1000)
        ->and(ProviderHttp::backoffMilliseconds(10))->toBe(1000);
});

test('the curl code decides, and only a connection failure is considered at all', function (): void {
    // Shaped the way Laravel wraps what Guzzle's curl handler raises: the curl code
    // and its description, carried in the message of both exceptions.
    $failure = function (string $message): ConnectionException {
        return new ConnectionException(
            $message,
            0,
            new ConnectException($message, new PsrRequest('POST', 'https://vendor.example.test'))
        );
    };

    expect(ProviderHttp::neverConnected($failure('cURL error 5: Could not resolve proxy: proxy.example.test')))->toBeTrue()
        ->and(ProviderHttp::neverConnected($failure(NEVER_RESOLVED)))->toBeTrue()
        ->and(ProviderHttp::neverConnected($failure(REFUSED)))->toBeTrue()
        ->and(ProviderHttp::neverConnected($failure(TIMED_OUT)))->toBeFalse()
        ->and(ProviderHttp::neverConnected($failure('cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL')))->toBeFalse()
        ->and(ProviderHttp::neverConnected($failure('cURL error 56: Recv failure: Connection reset by peer')))->toBeFalse()
        ->and(ProviderHttp::neverConnected($failure('the connection failed for reasons unstated')))->toBeFalse()
        // The same words outside a connection failure mean nothing.
        ->and(ProviderHttp::neverConnected(new RuntimeException(NEVER_RESOLVED)))->toBeFalse();
});

// ── Through a real driver and the chain ──────────────────────────────────────

test('an SMS whose vendor could not be reached at first is sent once, by the retry', function (): void {
    twilioFirstInChain();

    $vendor = vendorScript([NEVER_RESOLVED, 201]);
    fakeVendor('api.twilio.com/*', $vendor);

    $result = app(SmsDispatcherContract::class)->send(new SmsMessage('+15551234567', 'Your code is 123456'));

    expect($result->successful)->toBeTrue()
        ->and($result->driver)->toBe('twilio')
        ->and($vendor->calls)->toBe(2);

    // One provider attempt, recorded once: the retry happened inside it.
    $logs = IntegrationUsageLog::query()->get();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()?->status)->toBe(UsageStatus::SUCCESS);
});

test('an SMS that timed out goes to the next provider rather than being sent again', function (): void {
    twilioFirstInChain();

    $vendor = vendorScript([TIMED_OUT, 201]);
    fakeVendor('api.twilio.com/*', $vendor);

    $result = app(SmsDispatcherContract::class)->send(new SmsMessage('+15551234567', 'Your code is 123456'));

    expect($vendor->calls)->toBe(1)
        ->and($result->successful)->toBeTrue()
        ->and($result->driver)->toBe('log');

    $logs = IntegrationUsageLog::query()->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->driver)->toBe('twilio')
        ->and($logs[0]->error_code)->toBe('TRANSPORT_ERROR')
        ->and($logs[1]->driver)->toBe('log');
});

// ── The guard ────────────────────────────────────────────────────────────────

test('every vendor call goes through the retry policy', function (): void {
    $bypassing = [];

    foreach (File::allFiles(app_path('Modules/Integration')) as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'ProviderHttp.php') {
            continue;
        }

        // Code only: a docblock may mention Http::fake() as a way of testing.
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $file->getContents()) ?? '';

        // A word boundary, or `ProviderHttp::client()` — the compliant call — would
        // match its own prohibition.
        if (preg_match('/\bHttp::/', $code) === 1) {
            $bypassing[] = $file->getRelativePathname();
        }
    }

    expect($bypassing)->toBe([]);
});
