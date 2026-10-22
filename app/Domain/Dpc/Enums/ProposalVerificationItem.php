<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * The four confirmations Rule 1.1 requires before a DPC proposal is submitted.
 *
 * The order is the order the department works through them, and the order they are
 * shown in.
 */
enum ProposalVerificationItem: string
{
    case SanctionedStrength = 'sanctioned_strength';
    case RecruitmentRule = 'recruitment_rule';
    case SeniorityList = 'seniority_list';
    case PreviousDpcReconciliation = 'previous_dpc_reconciliation';

    /** Rule 4.4: every representation or complaint about the DPC is uploaded with its summary; none affects it. */
    case Representations = 'representations';

    /** Rule 4.4: the originals of every document are in the officer's original (Mul) folder. */
    case OriginalDocuments = 'original_documents';

    /** Rule 4.4: every officer meets clauses 8 to 11; the latest Recruitment Rule, notified on a date, is followed. */
    case RecruitmentRuleConformity = 'recruitment_rule_conformity';

    public function label(): string
    {
        return __("dpc.verification.items.{$this->value}.label");
    }

    public function statement(): string
    {
        return __("dpc.verification.items.{$this->value}.statement");
    }
}
