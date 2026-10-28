<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Models\User;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\RegistrationSeat;
use App\Integrations\Aadhaar\AadhaarOtpRequest;
use App\Integrations\Contracts\AadhaarOtpProvider;
use Illuminate\Contracts\Session\Session;
use SensitiveParameter;

/**
 * The two OTPs every officer takes before department registration (business
 * decision, 2026-10-22): an Aadhaar OTP to the mobile UIDAI holds, and an
 * e-mail OTP to the officer's .gov.in address — every time, for a new officer
 * and an existing one alike.
 *
 * **What was verified is fixed in the session, and the Register button is held
 * to it.** Sending the OTPs records the officer's e-mail, name and mobile and a
 * keyed fingerprint of the Aadhaar number; verifying marks that record verified.
 * {@see verified()} is what the registration request reads, and it compares the
 * submitted e-mail, name and mobile against the record — so editing a field in
 * the browser after the OTPs were verified is refused, not merely hidden by a
 * read-only input. Sending again for a seat replaces its record, which is how
 * "Change" works.
 *
 * **The Aadhaar number is never stored.** Not in the database and not in the
 * session: the session holds an HMAC of it, which is enough to prove the number
 * typed at verification is the number the OTP was requested for. The number is
 * sent to the provider and forgotten.
 */
class RegistrationVerificationService
{
    private const SESSION_KEY = 'registration_officers';

    public function __construct(
        private readonly OtpService $otp,
        private readonly AadhaarOtpProvider $aadhaar,
    ) {}

    /**
     * The officer already on the portal under this e-mail, if any. Case-blind,
     * because an address typed with a capital letter is the same mailbox.
     */
    public function existingOfficer(string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();
    }

    /**
     * Request both OTPs for a seat and record what they are for. The e-mail OTP
     * is issued only once UIDAI has accepted the Aadhaar request, so a mistyped
     * Aadhaar number does not also spend an e-mail.
     *
     * @param  array{email:string,name:string,mobile:string}  $officer
     */
    public function send(Session $session, RegistrationSeat $seat, array $officer, #[SensitiveParameter] string $aadhaar): AadhaarOtpRequest
    {
        $session->forget($this->key($seat));

        $request = $this->aadhaar->requestOtp($aadhaar);

        if (! $request->sent) {
            return $request;
        }

        $this->otp->issue($officer['email'], OtpChannel::Email, OtpPurpose::Registration);

        $session->put($this->key($seat), [
            'email' => $officer['email'],
            'name' => $officer['name'],
            'mobile' => $officer['mobile'],
            'aadhaar' => $this->fingerprint($aadhaar),
            'aadhaar_last4' => substr($aadhaar, -4),
            'txn' => $request->txn,
            'verified_at' => null,
        ]);

        return $request;
    }

    /**
     * Verify both codes. Returns the failures keyed by form field; empty means
     * the seat is verified.
     *
     * @return array<string, string>
     */
    public function verify(
        Session $session,
        RegistrationSeat $seat,
        #[SensitiveParameter] string $aadhaar,
        #[SensitiveParameter] string $emailCode,
        #[SensitiveParameter] string $aadhaarCode,
    ): array {
        $pending = $session->get($this->key($seat));

        if (! is_array($pending) || ! hash_equals((string) $pending['aadhaar'], $this->fingerprint($aadhaar))) {
            return ['aadhaar' => __('authui.register.errors.send_otp_first')];
        }

        $failures = [];
        $answer = $this->aadhaar->verifyOtp($aadhaar, (string) $pending['txn'], $aadhaarCode);

        if (! $answer->verified) {
            $failures['aadhaar_code'] = $answer->message;
        }

        if (! $this->otp->verify((string) $pending['email'], OtpPurpose::Registration, $emailCode)) {
            $failures['email_code'] = __('authui.register.errors.email_otp_invalid');
        }

        if ($failures === []) {
            $pending['verified_at'] = now()->getTimestamp();
            $session->put($this->key($seat), $pending);
        }

        return $failures;
    }

    /**
     * The seat's verified officer, if it is verified and still within the
     * verification window.
     *
     * @return array{email:string,name:string,mobile:string,aadhaar_last4:string}|null
     */
    public function verified(Session $session, RegistrationSeat $seat): ?array
    {
        $record = $session->get($this->key($seat));

        if (! is_array($record) || ! is_int($record['verified_at'] ?? null)) {
            return null;
        }

        $ttl = max(1, (int) config('dpc.aadhaar_otp.verification_ttl_minutes', 15)) * 60;

        if (now()->getTimestamp() > $record['verified_at'] + $ttl) {
            return null;
        }

        return [
            'email' => (string) $record['email'],
            'name' => (string) $record['name'],
            'mobile' => (string) $record['mobile'],
            'aadhaar_last4' => (string) $record['aadhaar_last4'],
        ];
    }

    /**
     * Whether the submitted officer is exactly the one whose OTPs were verified.
     *
     * @param  array{email:string,name:string,mobile:string}  $submitted
     */
    public function matches(Session $session, RegistrationSeat $seat, array $submitted): bool
    {
        $verified = $this->verified($session, $seat);

        return $verified !== null
            && mb_strtolower($verified['email']) === mb_strtolower(trim($submitted['email']))
            && $verified['name'] === trim($submitted['name'])
            && $verified['mobile'] === trim($submitted['mobile']);
    }

    public function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    private function key(RegistrationSeat $seat): string
    {
        return self::SESSION_KEY.'.'.$seat->value;
    }

    private function fingerprint(#[SensitiveParameter] string $aadhaar): string
    {
        return hash_hmac('sha256', $aadhaar, (string) config('app.key'));
    }
}
