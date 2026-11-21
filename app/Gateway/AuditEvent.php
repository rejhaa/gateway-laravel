<?php

declare(strict_types=1);

namespace App\Gateway;

/**
 * Every event the audit log records.
 */
enum AuditEvent: string
{
    case ClientConnected = 'client.connected';
    case UserSignedIn = 'user.signed_in';
    case ToolCalled = 'tool.called';
    case ToolFailed = 'tool.failed';
    case InputRejected = 'input.rejected';
    case OdooRead = 'odoo.read';
    case OdooWrite = 'odoo.write';
    case ProposalCreated = 'proposal.created';
    case ProposalDuplicate = 'proposal.duplicate';
    case ProposalDenied = 'proposal.denied';
    case ProposalAppliedByRule = 'proposal.applied_by_rule';
    case ProposalApproved = 'proposal.approved';
    case ProposalRejected = 'proposal.rejected';
    case ProposalApplied = 'proposal.applied';
    case ProposalStale = 'proposal.stale';
    case ProposalFailed = 'proposal.failed';
    case CardIssued = 'card.issued';
    case DecisionRefused = 'decision.refused';
}
