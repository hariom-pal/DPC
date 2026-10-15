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

    public function label(): string
    {
        return __("dpc.verification.items.{$this->value}.label");
    }

    public function statement(): string
    {
        return __("dpc.verification.items.{$this->value}.statement");
    }
}
