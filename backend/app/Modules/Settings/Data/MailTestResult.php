<?php

declare(strict_types=1);

namespace App\Modules\Settings\Data;

/**
 * What happened when a mail configuration was tested.
 *
 * A result rather than an exception, for the reason ADR 0017 gives about provider
 * dispatch: an unreachable host is an expected outcome to report, not a fault to
 * crash on. Exceptions stay reserved for programming and configuration faults.
 *
 * It carries no credential and no transport detail beyond the failure's class —
 * a transport exception's message frequently contains the host, the username and
 * occasionally the credential it tried.
 */
final readonly class MailTestResult
{
    private function __construct(
        public bool $succeeded,
        public string $status,
        public ?string $recipient = null,
        /** @var array<int, string> */
        public array $missing = [],
        public ?string $failure = null,
    ) {}

    public static function sent(string $recipient): self
    {
        return new self(true, 'sent', recipient: $recipient);
    }

    /**
     * @param  array<int, string>  $missing
     */
    public static function incomplete(array $missing): self
    {
        return new self(false, 'incomplete', missing: $missing);
    }

    public static function failed(string $failure, string $recipient): self
    {
        return new self(false, 'failed', recipient: $recipient, failure: $failure);
    }

    /**
     * The result as an API payload, and as an audit context.
     *
     * The same shape for both on purpose: what is safe to hand an operator is what is
     * safe to write down, and maintaining two shapes is how one of them acquires a
     * field the other was careful to leave out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'recipient' => $this->recipient,
            'missing' => $this->missing === [] ? null : $this->missing,
            'failure' => $this->failure,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
