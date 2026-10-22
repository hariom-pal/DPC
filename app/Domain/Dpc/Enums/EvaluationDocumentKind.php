<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * The employee-wise documents the Authorized Officer uploads inside a DPC proposal,
 * each traced to the page of the Coder Instructions that asks for it.
 */
enum EvaluationDocumentKind: string
{
    /** A graded CR for one year. Page 5: uploaded with the "no duplicate CR" undertaking. */
    case CrReport = 'cr_report';

    /** Page 3: a CR put up without a grading is still uploaded. */
    case CrWithoutGrading = 'cr_without_grading';

    /** Page 3: compulsory when the reporting, reviewing or accepting remark is missing. */
    case CrNrc = 'cr_nrc';

    /** Page 3: the channel document, with the undertaking that CRs are signed as per channel. */
    case CrChannel = 'cr_channel';

    /** Rule 4.1: every IPR is uploaded. */
    case PropertyReturn = 'property_return';

    /** Rule 4.1: the department's decision to accept an IPR submitted after 31 January. */
    case PropertyLateDecision = 'property_late_decision';

    /** The employee's signed PH undertaking, uploaded by the Authorized Officer. */
    case PhUndertaking = 'ph_undertaking';

    /** The order behind a pending departmental enquiry, charge-sheet, show-cause or suspension (rule 2.2). */
    case VigilanceOrder = 'vigilance_order';

    /** Rule 2.3: the order of an officer on deputation without a sanctioned setup. */
    case DeputationOrder = 'deputation_order';

    /** Rule 4.4: the officer's letter refusing promotion to this post. */
    case RefusalLetter = 'refusal_letter';

    /** Rule 4.4: the order of the DPC whose promotion the officer refused. */
    case RefusalDpcOrder = 'refusal_dpc_order';

    /** Any other supporting document the Authorized Officer attaches. */
    case Other = 'other';

    public function label(): string
    {
        return __("dpc.employee_details.documents.kinds.{$this->value}");
    }

    /**
     * Whether the document belongs to one CR or IPR year.
     */
    public function isYearly(): bool
    {
        return in_array($this, [
            self::CrReport, self::CrWithoutGrading, self::CrNrc,
            self::PropertyReturn, self::PropertyLateDecision,
        ], true);
    }

    /**
     * Whether the upload is refused without the digital undertaking ticked.
     *
     * A CR copy carries the "no duplicate CR for the year" undertaking (page 5), and
     * the PH undertaking carries the officer's confirmation that the file is the
     * employee's own signed undertaking.
     */
    public function requiresUndertaking(): bool
    {
        return in_array($this, [self::CrReport, self::CrWithoutGrading, self::PhUndertaking], true);
    }

    /**
     * The documents that belong to one CR year, uploaded from the CR section.
     *
     * @return list<self>
     */
    public static function yearlyCrKinds(): array
    {
        return [self::CrReport, self::CrWithoutGrading, self::CrNrc];
    }
}
