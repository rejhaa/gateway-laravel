<?php

declare(strict_types=1);

namespace App\Systems\Mail;

/**
 * Reads the Authentication-Results headers of a message (RFC 8601), where the
 * receiving mail server records what SPF, DKIM and DMARC found.
 *
 * Only a header that starts with the receiving server's own id counts,
 * because a sender can add a header of this name too. The receiving server
 * has to remove an incoming header that carries its id, as section 5 of the
 * RFC requires.
 *
 * The header also holds text the sender chose, such as the envelope address
 * in smtp.mailfrom. The server writes such text inside a quoted string or a
 * comment when it holds a separator, so the header is read token by token and
 * nothing inside a quoted string or a comment starts a result.
 */
final class AuthenticationResults
{
    private const WORD = 'word';

    /**
     * Whether the receiving server found that the message comes from the
     * domain of its From address.
     *
     * A DMARC pass for that domain says so. Without one, a DKIM or an SPF pass
     * says so only when it names that same domain, because every sender passes
     * both for a domain of their own. A DMARC fail for the domain settles it
     * the other way, whatever else the headers hold.
     *
     * @param  list<string>  $headers  the raw value of each Authentication-Results header
     */
    public static function verifies(array $headers, string $authservId, string $fromDomain): bool
    {
        $fromDomain = strtolower($fromDomain);
        $passed = false;

        foreach ($headers as $header) {
            foreach (self::results($header, $authservId) as $result) {
                $domains = array_map(self::domain(...), match ($result['method']) {
                    'dmarc' => [$result['properties']['header.from'] ?? null],
                    'dkim' => [$result['properties']['header.d'] ?? null, $result['properties']['header.i'] ?? null],
                    'spf' => [$result['properties']['smtp.mailfrom'] ?? null],
                    default => [],
                });

                if (! in_array($fromDomain, $domains, true)) {
                    continue;
                }

                if ($result['method'] === 'dmarc' && $result['result'] === 'fail') {
                    return false;
                }

                $passed = $passed || $result['result'] === 'pass';
            }
        }

        return $passed;
    }

    /**
     * The results of one header, or none when another server wrote it or it
     * is malformed.
     *
     * @return list<array{method: string, result: string, properties: array<string, string>}>
     */
    private static function results(string $header, string $authservId): array
    {
        $segments = self::segments($header);
        $writer = array_shift($segments) ?? [];

        if (! self::isWriter($writer, $authservId)) {
            return [];
        }

        return array_values(array_filter(array_map(self::result(...), $segments)));
    }

    /**
     * The first segment is the server's id alone, or the id and a version
     * number.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function isWriter(array $tokens, string $authservId): bool
    {
        $words = array_column($tokens, 1);

        return array_unique(array_column($tokens, 0)) === [self::WORD]
            && $words[0] === strtolower($authservId)
            && (count($words) === 1 || (count($words) === 2 && ctype_digit($words[1])));
    }

    /**
     * One result, such as `dkim=pass header.d=taiyuan.example`, or null when
     * the segment does not start with a method and its result. A property
     * named twice has no value, because nobody can say which one is meant.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array{method: string, result: string, properties: array<string, string>}|null
     */
    private static function result(array $tokens): ?array
    {
        $equals = array_search(['=', '='], $tokens, true);

        if ($equals === false || $equals === 0 || ($tokens[$equals + 1][0] ?? null) !== self::WORD) {
            return null;
        }

        $method = '';

        foreach (array_slice($tokens, 0, $equals) as [$type, $text]) {
            if ($type !== self::WORD) {
                return null;
            }

            $method .= $text;
        }

        $properties = [];

        for ($i = $equals + 2; $i + 2 < count($tokens); $i++) {
            if ($tokens[$i][0] === self::WORD && $tokens[$i + 1][0] === '=' && $tokens[$i + 2][0] === self::WORD) {
                $properties[$tokens[$i][1]] = array_key_exists($tokens[$i][1], $properties) ? '' : $tokens[$i + 2][1];
                $i += 2;
            }
        }

        return ['method' => explode('/', $method)[0], 'result' => $tokens[$equals + 1][1], 'properties' => $properties];
    }

    /**
     * The header's tokens in lowercase, split at each semicolon that stands
     * outside a quoted string and a comment. A header with a comment or a
     * quoted string that never ends has no segments.
     *
     * @return list<list<array{0: string, 1: string}>> each token as its type (word or =) and its text
     */
    private static function segments(string $header): array
    {
        $segments = [[]];
        $word = null;
        $length = strlen($header);

        $endWord = function () use (&$segments, &$word): void {
            if ($word !== null) {
                $segments[array_key_last($segments)][] = [self::WORD, strtolower($word)];
                $word = null;
            }
        };

        for ($i = 0; $i < $length; $i++) {
            $char = $header[$i];

            if ($char === '(') {
                $depth = 1;

                while ($depth > 0) {
                    if (++$i >= $length) {
                        return [];
                    }

                    if ($header[$i] === '\\') {
                        $i++;
                    } else {
                        $depth += ['(' => 1, ')' => -1][$header[$i]] ?? 0;
                    }
                }

                $endWord();
            } elseif ($char === '"') {
                $word ??= '';

                while (true) {
                    if (++$i >= $length) {
                        return [];
                    }

                    if ($header[$i] === '"') {
                        break;
                    }

                    if ($header[$i] === '\\' && ++$i >= $length) {
                        return [];
                    }

                    $word .= $header[$i];
                }
            } elseif ($char === ';') {
                $endWord();
                $segments[] = [];
            } elseif ($char === '=') {
                $endWord();
                $segments[array_key_last($segments)][] = ['=', '='];
            } elseif ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                $endWord();
            } else {
                $word .= $char;
            }
        }

        $endWord();

        return $segments;
    }

    /** The domain of a property that holds a domain or an address, such as `@taiyuan.example`. */
    private static function domain(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return rtrim(str_contains($value, '@') ? substr((string) strrchr($value, '@'), 1) : $value, '.');
    }
}
