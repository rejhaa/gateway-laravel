<?php

declare(strict_types=1);

namespace App\Evidence;

/**
 * Tells whether a sender's address is at one of the mail domains a system of
 * record holds for an organization.
 */
final class SenderChecker
{
    /**
     * The address belongs when its domain is one of the organization's mail
     * domains. Domains are compared whole, so a subdomain of one of them is
     * another domain. An organization with no mail domain has no senders.
     *
     * @param  list<string>  $domains  the organization's mail domains, as domains() returns them
     */
    public function belongsTo(string $address, array $domains): bool
    {
        $at = strrchr(strtolower($address), '@');

        return $at !== false && in_array(substr($at, 1), $domains, true);
    }

    /**
     * The domains a person wrote into one text field, in lowercase. They are
     * separated by commas, semicolons or white space, and a domain may be
     * written with the @ in front of it. An entry that is no domain, such as
     * a whole address or a web address, stays as written and equals the
     * domain of no sender.
     *
     * @return list<string>
     */
    public static function domains(string $text): array
    {
        $entries = preg_split('/[\s,;]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $domains = array_map(fn (string $entry): string => ltrim($entry, '@'), $entries === false ? [] : $entries);

        return array_values(array_unique(array_filter($domains, fn (string $domain): bool => $domain !== '')));
    }
}
