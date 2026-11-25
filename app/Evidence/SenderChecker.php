<?php

declare(strict_types=1);

namespace App\Evidence;

/**
 * Tells whether a sender's address belongs to an organization, from the
 * addresses a system of record holds for that organization.
 */
final class SenderChecker
{
    /**
     * Domains where anyone can get an address, so the domain names no
     * organization and only the whole address counts. A subdomain of one of
     * them is shared too.
     */
    public const SHARED_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'ymail.com',
        'rocketmail.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'pm.me',
        'gmx.com', 'gmx.net', 'gmx.de', 'gmx.at', 'gmx.ch', 'web.de', 'mail.com', 'zoho.com', 'zohomail.com',
        'yandex.com', 'yandex.ru', 'mail.ru', 'fastmail.com', 'fastmail.fm', 'tutanota.com', 'tuta.io', 'hey.com',
        'yahoo.co.uk', 'yahoo.co.jp', 'yahoo.co.in', 'yahoo.de', 'yahoo.fr', 'yahoo.it', 'yahoo.es', 'yahoo.com.tw',
        'yahoo.com.hk', 'yahoo.com.vn', 'yahoo.com.sg', 'yahoo.com.au', 'yahoo.com.br', 'yahoo.ca',
        'hotmail.co.uk', 'hotmail.fr', 'hotmail.de', 'hotmail.it', 'hotmail.es', 'live.co.uk', 'live.fr', 'live.de',
        'outlook.de', 'outlook.fr', 'outlook.it', 'outlook.es', 'outlook.jp',
        'qq.com', 'foxmail.com', '163.com', '126.com', 'yeah.net', 'sina.com', 'sina.cn', 'sohu.com', 'aliyun.com',
        '139.com', '189.cn', '21cn.com', 'tom.com',
        'hinet.net', 'seed.net.tw', 'pchome.com.tw', 'kimo.com', 'so-net.net.tw', 'giga.net.tw',
        'vnn.vn', 'fpt.vn', 'viettel.vn', 'vnpt.vn',
        'naver.com', 'daum.net', 'hanmail.net', 'nate.com', 'kakao.com',
        'docomo.ne.jp', 'ezweb.ne.jp', 'softbank.ne.jp', 'nifty.com', 'biglobe.ne.jp', 'ocn.ne.jp',
        'rediffmail.com',
        't-online.de', 'freenet.de', 'orange.fr', 'wanadoo.fr', 'free.fr', 'laposte.net', 'sfr.fr', 'libero.it',
        'virgilio.it', 'tiscali.it', 'alice.it', 'seznam.cz', 'wp.pl', 'o2.pl', 'interia.pl', 'onet.pl', 'bluewin.ch',
        'telia.com', 'luukku.com', 'kolumbus.fi', 'elisanet.fi', 'saunalahti.fi', 'inet.fi', 'suomi24.fi', 'netti.fi',
        'comcast.net', 'verizon.net', 'att.net', 'sbcglobal.net', 'bellsouth.net', 'cox.net', 'charter.net', 'earthlink.net',
        'btinternet.com', 'sky.com', 'virginmedia.com', 'talktalk.net',
    ];

    /**
     * The address belongs when it is one of the organization's addresses, or
     * when its domain is the domain of one of them and is not a shared one.
     *
     * @param  list<string>  $addresses  every address the organization has on record
     */
    public function belongsTo(string $address, array $addresses): bool
    {
        $address = strtolower($address);
        $addresses = array_map(strtolower(...), $addresses);

        if (in_array($address, $addresses, true)) {
            return true;
        }

        $domain = self::domain($address);

        return ! self::isShared($domain) && in_array($domain, array_map(self::domain(...), $addresses), true);
    }

    private static function isShared(string $domain): bool
    {
        return array_any(self::SHARED_DOMAINS, fn (string $shared): bool => $domain === $shared || str_ends_with($domain, '.'.$shared));
    }

    private static function domain(string $address): string
    {
        return substr((string) strrchr($address, '@'), 1);
    }
}
