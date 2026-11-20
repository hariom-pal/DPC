<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\Authentication\Services\OtpService;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Candidate sign-in by registered mobile number and OTP (2026-11-19), on the
 * application's own OTP service (expiry, resend cooldown, hourly limit, attempts; the
 * code is stored hashed and never logged) and the SMS gateway behind it.
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
     * Every verification the mobile is registered on, any DPC.
     *
     * @return Collection<int, VerificationParticipant>
     */
    public function participantsFor(string $mobile): Collection
    {
        return VerificationParticipant::query()
            ->whereHas('row', fn ($q) => $q->where('mobile', 'like', '%'.substr($mobile, -4)))
            ->with('row')
            ->get()
            ->filter(fn (VerificationParticipant $p): bool => self::normaliseMobile((string) $p->row->mobile) === $mobile)
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

        if ($normalised === null || $this->participantsFor($normalised)->isEmpty()) {
            $this->security->record('candidate_verification.unknown_mobile', null, $request, ['mobile_tail' => $normalised === null ? null : substr($normalised, -4)]);

            return;
        }

        $this->otp->issue($normalised, OtpChannel::Sms, OtpPurpose::CandidateVerification);
    }

    /**
     * The verifications a correct code opens, grouped by officer: one group signs in,
     * several ask for the Employee ID. Null when the code fails.
     *
     * @return array<string, list<int>>|null identity => participant ids
     */
    public function verify(string $mobile, string $code, Request $request): ?array
    {
        $normalised = self::normaliseMobile($mobile);

        if ($normalised === null || ! $this->otp->verify($normalised, OtpPurpose::CandidateVerification, $code)) {
            $this->security->record('candidate_verification.otp_failed', null, $request, ['mobile_tail' => $normalised === null ? null : substr($normalised, -4)]);

            return null;
        }

        $groups = [];
        foreach ($this->participantsFor($normalised) as $participant) {
            $groups[self::identity($participant)][] = $participant->id;
        }

        return $groups;
    }

    /**
     * The officer among several on one mobile whose Employee ID was typed.
     *
     * @param  list<int>  $participantIds
     * @return list<int>
     */
    public function choose(array $participantIds, string $employeeNumber): array
    {
        $wanted = 'employee:'.mb_strtoupper(trim($employeeNumber));

        return VerificationParticipant::query()->whereIn('id', $participantIds)->with('row')->get()
            ->filter(fn (VerificationParticipant $p): bool => trim($employeeNumber) !== '' && self::identity($p) === $wanted)
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /** One officer: their Employee ID, or — with none — only their own Seniority List row. */
    public static function identity(VerificationParticipant $participant): string
    {
        $employee = trim((string) $participant->row->employee_number);

        return $employee === '' ? 'row:'.$participant->dpc_evaluation_row_id : 'employee:'.mb_strtoupper($employee);
    }
}
