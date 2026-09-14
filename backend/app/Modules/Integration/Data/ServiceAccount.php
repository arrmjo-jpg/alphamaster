<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

use App\Modules\Integration\Rules\ServiceAccountPrivateKey;

/**
 * A Google service account, read from the JSON file Firebase issues.
 *
 * The Admin takes the file whole — what an operator downloads from the Firebase console
 * and pastes — and this is where it becomes the three values FCM v1 needs. Everything
 * else in the file (the key's id, the client id, the certificate URLs) is dropped here:
 * nothing reads it, and keeping secret material nothing uses is a liability with no
 * return (ADR 0045 §2). The document itself is never stored.
 *
 * Parsing answers with a reason rather than throwing, so a validation rule can say what
 * was wrong without anything quoting the value. No reason carries the document's
 * contents.
 */
final readonly class ServiceAccount
{
    /**
     * The token endpoint the driver addresses its assertion to, and the only one a
     * service account may name. A file pointing anywhere else is not one FCM can use.
     */
    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /**
     * A real service-account file is about 2,400 characters. Sixteen thousand leaves room
     * for a 4096-bit key and pretty-printing, and stops anything that is neither long
     * before it is decoded.
     */
    public const MAX_DOCUMENT_LENGTH = 16384;

    /** Google's own rule: 6–30 characters, a lowercase letter first, no trailing hyphen. */
    public const PROJECT_ID_PATTERN = '/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/';

    /** FCM v1 accepts only a service account as the issuer of the assertion. */
    public const CLIENT_EMAIL_PATTERN = '/@[a-z0-9.-]+\.gserviceaccount\.com$/i';

    private function __construct(
        public string $projectId,
        public string $clientEmail,
        public string $privateKey,
    ) {}

    /**
     * The service account in the document, or the reason it is not one.
     *
     * @return self|'json'|'type'|'project_id'|'client_email'|'private_key'|'token_uri'
     */
    public static function parse(string $document): self|string
    {
        if (strlen($document) > self::MAX_DOCUMENT_LENGTH) {
            return 'json';
        }

        try {
            $fields = json_decode($document, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'json';
        }

        if (! is_array($fields) || array_is_list($fields)) {
            return 'json';
        }

        if (($fields['type'] ?? null) !== 'service_account') {
            return 'type';
        }

        $projectId = $fields['project_id'] ?? null;

        if (! is_string($projectId) || preg_match(self::PROJECT_ID_PATTERN, $projectId) !== 1) {
            return 'project_id';
        }

        $clientEmail = $fields['client_email'] ?? null;

        if (! is_string($clientEmail)
            || strlen($clientEmail) > 254
            || filter_var($clientEmail, FILTER_VALIDATE_EMAIL) === false
            || preg_match(self::CLIENT_EMAIL_PATTERN, $clientEmail) !== 1) {
            return 'client_email';
        }

        $privateKey = $fields['private_key'] ?? null;

        if (! is_string($privateKey) || ! ServiceAccountPrivateKey::isUsable($privateKey)) {
            return 'private_key';
        }

        if (($fields['token_uri'] ?? null) !== self::TOKEN_URI) {
            return 'token_uri';
        }

        return new self($projectId, $clientEmail, $privateKey);
    }

    /**
     * The credential the provider row stores, in the shape the driver reads.
     *
     * @return array{project_id: string, client_email: string, private_key: string}
     */
    public function credentials(): array
    {
        return [
            'project_id' => $this->projectId,
            'client_email' => $this->clientEmail,
            'private_key' => $this->privateKey,
        ];
    }
}
