<?php

declare(strict_types=1);

use App\Processes\InboundPurchasing\SharedConditions;
use Tests\Support\RuleCase;

// Bug: a condition is dropped or its phrase is wrong. Each row starts from a
// passing confirmation and changes one thing. The rule tier switch is checked
// by the gateway before any rule and is tested with ProposalService.
it('fails each shared condition alone with its phrase', function (array $change, array $phrases): void {
    $case = RuleCase::confirmation()->with(...$change);

    expect(SharedConditions::failures($case->write(), $case->lineFacts(), $case->evidence()))->toBe($phrases);
})->with([
    'nothing changed' => [[], []],
    'the order is not confirmed' => [
        ['facts' => ['orderState' => 'draft']],
        ['the order is not a confirmed purchase order'],
    ],
    'a field beyond the three' => [
        ['values' => ['name' => 'Heads, revised']],
        ['the change is more than the planned date and the confirmed quantity and date'],
    ],
    'the quote does not state the quantity' => [
        ['evidence' => ['quote' => 'VG-HEAD-V1 V1 forged iron head delivery 30 November 2026']],
        ['no quoted passage that names V1 forged iron head (VG-HEAD-V1) states the confirmed quantity 120 unambiguously'],
    ],
    'the document has no text layer' => [
        ['evidence' => ['found' => false]],
        ['the document has no text layer, so the quote was not checked'],
    ],
    // Bug: a forwarder, a colleague or a stranger who knows the order number moves a date by rule.
    'the sender is at another organization' => [
        ['evidence' => ['fromAddress' => 'tracking@forwarder.example']],
        ['the sender tracking@forwarder.example is not an address or a domain of Tai-Yuan Forge & Shaft in Odoo'],
    ],
    // Bug: a forged From header passes as the supplier.
    'the mail server did not verify the sender' => [
        ['evidence' => ['fromVerified' => false]],
        ['the mail server did not verify that the message comes from sales@taiyuan.example'],
    ],
    // Bug: the checks run in another order, so a stranger's mail is reported as the supplier's own address failing verification.
    'a sender at another organization that the mail server did not verify' => [
        ['evidence' => ['fromAddress' => 'x@attacker.example', 'fromVerified' => false]],
        ['the sender x@attacker.example is not an address or a domain of Tai-Yuan Forge & Shaft in Odoo'],
    ],
    'the message has no single sender address' => [
        ['evidence' => ['fromAddress' => null]],
        ['the message has no single sender address'],
    ],
    // Bug: a supplier nobody gave an address in Odoo accepts every sender.
    'the supplier has no address in Odoo' => [
        ['facts' => ['supplierAddresses' => []]],
        ['Tai-Yuan Forge & Shaft has no email address in Odoo to check the sender against'],
    ],
    'the message does not name the order' => [
        ['evidence' => ['subject' => 'Order confirmation']],
        ["the message does not name the order P00104 or the supplier's reference TYF-OC-26-0918"],
    ],
    // Bug: a superseded confirmation is applied.
    'a later message names the order' => [
        ['evidence' => ['laterSubjects' => ['Re: P00104 new dates']]],
        ['a later message (Re: P00104 new dates) also names the order'],
    ],
    // Bug: one document is applied twice by rule.
    'the message was already applied to the line by rule' => [
        ['evidence' => ['appliedByRule' => 7]],
        ['the same message was already applied to the line by rule in proposal 7'],
    ],
    'the confirmed quantity is not the ordered one' => [
        ['facts' => ['orderedQty' => 150.0]],
        ['the confirmed quantity 120 is not the 150 ordered'],
    ],
]);

// Bug: any later mail about anything blocks the rule.
it('passes when the later message is about another order', function (): void {
    $case = RuleCase::confirmation()->with(evidence: ['laterSubjects' => ['Order confirmation P00105']]);

    expect(SharedConditions::failures($case->write(), $case->lineFacts(), $case->evidence()))->toBe([]);
});

// Bug: every confirmation from a clerk who is not yet a contact in Odoo goes to Riku.
it('passes when the sender is a verified address at the supplier\'s domain that Odoo does not hold', function (): void {
    $case = RuleCase::confirmation()->with(evidence: ['fromAddress' => 'new.clerk@taiyuan.example']);

    expect(SharedConditions::failures($case->write(), $case->lineFacts(), $case->evidence()))->toBe([]);
});

// Bug: a shafts row confirms the heads line.
it('fails every written value when the quote names another product', function (): void {
    $case = RuleCase::confirmation()->with(evidence: ['quote' => 'VG-SHAFT-V1 steel shaft 120 pcs delivery 30 November 2026']);

    expect(SharedConditions::failures($case->write(), $case->lineFacts(), $case->evidence()))->toBe([
        'no quoted passage that names V1 forged iron head (VG-HEAD-V1) states the confirmed quantity 120 unambiguously',
        'no quoted passage that names V1 forged iron head (VG-HEAD-V1) states the confirmed date 2026-11-30 unambiguously',
    ]);
});
