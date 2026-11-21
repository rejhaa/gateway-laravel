<?php

declare(strict_types=1);

namespace App\Gateway;

enum ProposalStatus: string
{
    /** Waits for the user. */
    case Pending = 'pending';

    /** Claimed for writing. Stays here only when the process died mid-write. */
    case Applying = 'applying';

    /** Written to Odoo, by a rule or after approval. */
    case Applied = 'applied';

    /** The user said no, with a reason. */
    case Rejected = 'rejected';

    /** Refused by the policy. */
    case Denied = 'denied';

    /** Approved, but the record had changed since it was proposed. Nothing was written. */
    case Stale = 'stale';

    /** Odoo refused or errored. */
    case Failed = 'failed';
}
