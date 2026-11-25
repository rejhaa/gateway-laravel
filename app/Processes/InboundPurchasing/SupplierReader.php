<?php

declare(strict_types=1);

namespace App\Processes\InboundPurchasing;

use App\Evidence\SenderChecker;
use App\Gateway\Actor;
use App\Systems\Odoo\Exceptions\OdooException;
use App\Systems\Odoo\OdooClient;

/**
 * Reads the supplier behind a partner on an order or a bill, as the actor.
 */
final class SupplierReader
{
    private const FIELDS = ['name', 'commercial_partner_id', 'x_mail_domains'];

    public function __construct(private readonly OdooClient $odoo) {}

    /**
     * The company the partner belongs to, which is the partner itself or the
     * company of a contact person, with the mail domains a person entered
     * for that company in x_mail_domains. The email addresses of the company
     * and its contacts decide nothing, because an address at gmail.com would
     * make every sender at gmail.com the supplier's.
     *
     * @return array{name: string, domains: list<string>}
     *
     * @throws OdooException
     */
    public function read(Actor $actor, int $partnerId): array
    {
        $partner = $this->odoo->read($actor, 'res.partner', [$partnerId], self::FIELDS)[0];
        $companyId = is_array($partner['commercial_partner_id']) ? (int) $partner['commercial_partner_id'][0] : $partnerId;
        $company = $companyId === $partnerId ? $partner : $this->odoo->read($actor, 'res.partner', [$companyId], self::FIELDS)[0];
        $domains = is_string($company['x_mail_domains']) ? $company['x_mail_domains'] : '';

        return ['name' => (string) $company['name'], 'domains' => SenderChecker::domains($domains)];
    }
}
