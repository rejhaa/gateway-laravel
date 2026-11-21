<?php

declare(strict_types=1);

namespace App\Gateway;

use Carbon\CarbonImmutable;

/**
 * The token a card in the Claude chat decides with (DESIGN.md, section 4).
 *
 * It is a signed string, not a database row. It holds the user id, a random
 * token id, the purpose `card` and an expiry, with an HMAC under APP_KEY. The
 * card sends it in the X-Card-Token header, so a Passport bearer token is
 * never mistaken for one. issue() and check() are the only code that makes or
 * reads one.
 */
final readonly class CardToken
{
    public const HEADER = 'X-Card-Token';

    private const PURPOSE = 'card';

    private function __construct(
        public int $userId,
        public string $id,
        public CarbonImmutable $expiresAt,
        public string $value,
    ) {}

    /** A new token for the user, valid for config('gateway.card.token_hours'). */
    public static function issue(int $userId): self
    {
        $id = bin2hex(random_bytes(8));
        $expiresAt = CarbonImmutable::now()->addHours((int) config('gateway.card.token_hours'))->startOfSecond();
        $payload = self::encode((string) json_encode(['u' => $userId, 't' => $id, 'p' => self::PURPOSE, 'e' => $expiresAt->getTimestamp()]));

        return new self($userId, $id, $expiresAt, $payload.'.'.self::signature($payload));
    }

    /** The token, or null when it is missing, tampered with, for another purpose or expired. */
    public static function check(?string $value): ?self
    {
        $parts = explode('.', (string) $value);

        if (count($parts) !== 2 || ! hash_equals(self::signature($parts[0]), $parts[1])) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);

        if (! is_array($claims) || ! is_int($claims['u'] ?? null) || ! is_string($claims['t'] ?? null)
            || ($claims['p'] ?? null) !== self::PURPOSE || ! is_int($claims['e'] ?? null)) {
            return null;
        }

        $expiresAt = CarbonImmutable::createFromTimestamp($claims['e']);

        if (! $expiresAt->isFuture()) {
            return null;
        }

        return new self($claims['u'], $claims['t'], $expiresAt, (string) $value);
    }

    private static function signature(string $payload): string
    {
        return self::encode(hash_hmac('sha256', $payload, (string) config('app.key'), true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
