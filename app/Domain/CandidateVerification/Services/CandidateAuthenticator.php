<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\Authentication\Services\OtpService;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Candidate sign-in to the Candidate Portal (2026-11-19; one portal since 2026-11-20):
 * the registered mobile number and an OTP, on the application's own OTP service
 * (expiry, resend cooldown, hourly limit, attempts; the code is stored hashed and
 * never logged) — then the candidate's eSign, which checks their name, before the
 * portal opens (the controller's step).
 *
 * The screen answers the same whether or not the number is registered, so it tells no
 * one which numbers exist; a number that is not is recorded as a failed attempt. A
 * number shared by several officers is never taken to be any one of them: the
 * Employee ID decides, and an officer with none is told to see their department.
 */
final class CandidateAuthenticator
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly SecurityLog $security,
    ) {}

    public static function normaliseMobile(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) < 10 ? null : substr($digits, -10);
    }

    /**
     * Every Seniority List row the mobile is registered on, on a DPC the portal shows
     * (CandidateSession::VISIBLE): any DPC, open or long concluded.
     *
     * @return Collection<int, DpcEvaluationRow>
     */
    public function rowsFor(string $mobile): Collection
    {
        return DpcEvaluationRow::query()
            ->where('mobile', 'like', '%'.substr($mobile, -4))
            ->whereHas('proposal', fn ($q) => $q->whereIn('status', CandidateSession::VISIBLE))
            ->get()
            ->filter(fn (DpcEvaluationRow $row): bool => self::normaliseMobile((string) $row->mobile) === $mobile)
            ->values()
            ->toBase();
    }

    /**
     * Send a code if the number is registered; otherwise record the attempt. Throws the
     * OTP service's throttle when a code was sent too recently.
     */
    public function requestCode(string $mobile, Request $request): void
    {
        $normalised = self::normaliseMobile($mobile);

        if ($normalised === null || $this->rowsFor($normalised)->isEmpty()) {
            $this->security->record('candidate_verification.unknown_mobile', null, $request, ['mobile_tail' => $normalised === null ? null : substr($normalised, -4)]);

            return;
        }

        $this->otp->issue($normalised, OtpChannel::Sms, OtpPurpose::CandidateVerification);
    }

    /**
     * The rows a correct code opens, grouped by officer: one group goes on to the
     * eSign, several ask for the Employee ID first. Null when the code fails.
     *
     * @return array<string, list<int>>|null identity => row ids
     */
    public function verify(string $mobile, string $code, Request $request): ?array
    {
        $normalised = self::normaliseMobile($mobile);

        if ($normalised === null || ! $this->otp->verify($normalised, OtpPurpose::CandidateVerification, $code)) {
            $this->security->record('candidate_verification.otp_failed', null, $request, ['mobile_tail' => $normalised === null ? null : substr($normalised, -4)]);

            return null;
        }

        $groups = [];
        foreach ($this->rowsFor($normalised) as $row) {
            $groups[self::identity($row)][] = (int) $row->id;
        }

        return $groups;
    }

    /**
     * The officer among several on one mobile whose Employee ID was typed.
     *
     * @param  list<int>  $rowIds
     * @return list<int>
     */
    public function choose(array $rowIds, string $employeeNumber): array
    {
        $wanted = 'employee:'.mb_strtoupper(trim($employeeNumber));

        return DpcEvaluationRow::query()->whereIn('id', $rowIds)->get()
            ->filter(fn (DpcEvaluationRow $row): bool => trim($employeeNumber) !== '' && self::identity($row) === $wanted)
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /** One officer: their Employee ID, or — with none — only their own Seniority List row. */
    public static function identity(DpcEvaluationRow $row): string
    {
        $employee = trim((string) $row->employee_number);

        return $employee === '' ? 'row:'.$row->id : 'employee:'.mb_strtoupper($employee);
    }
}
