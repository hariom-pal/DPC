<?php

declare(strict_types=1);

namespace App\Domain\Committee\Services;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Finding a person to put on a DPC committee, and never creating them twice
 * (change register of 2026-08-26, item 6).
 *
 * > *Search existing user by Email or Mobile Number. If found, select the existing
 * > user. While entering Email or Mobile for a new member, automatically check
 * > whether the user already exists. If already available, show "This user already
 * > exists" and provide "Use Existing User" instead of creating duplicate users.*
 *
 * ### One rule, two screens
 *
 * The register describes two paths — *search* and *create* — and they ask the same
 * question. If they asked it differently, the create path would eventually admit
 * somebody the search path could already find, which is precisely the duplicate the
 * register is guarding against. So {@see matching()} is the only lookup, and both
 * the search box and the live check behind the create form call it.
 *
 * ### Why the whole portal, not just Commission accounts
 *
 * The search deliberately spans **every** account rather than only those holding a
 * Commission role. The register is explicit that committee membership confers no
 * role and that *"a user may simultaneously be AO, AH, a Promotion Candidate and
 * also become a Committee Member for a particular DPC"* — so restricting the search
 * to Commission role-holders would hide exactly the people the register says are
 * eligible, and the officer, unable to find them, would create the duplicate this
 * class exists to prevent.
 *
 * ### Normalisation is where duplicates actually get in
 *
 * `9425012345`, `+91 9425012345` and `094250-12345` are one mobile number, and
 * `A.Verma@cg.gov.in` and `a.verma@cg.gov.in` are one mailbox. A lookup that
 * compares raw strings reports "no such user" for all four and cheerfully creates a
 * second account. {@see normaliseMobile()} reduces a number to its last ten digits
 * and e-mail is compared case-insensitively, so the check answers the question a
 * person is actually asking.
 */
class CommitteeMemberDirectory
{
    /**
     * How many matches a search returns.
     *
     * Small on purpose: this answers "is this person already in the portal", and a
     * query that matches thirty people has not answered it. The officer narrows
     * the term instead.
     */
    public const LIMIT = 10;

    /**
     * Accounts matching an e-mail address or a mobile number.
     *
     * **Exact matching, not partial.** A committee member is identified by a
     * mailbox or a number they own, and `LIKE '%9425%'` would return strangers who
     * merely share four digits — offering the officer a list of other people to
     * mistakenly seat. The register asks for search *by* email and mobile, which is
     * a lookup rather than a browse.
     *
     * The name is searched too, and only as a prefix, because an officer who knows
     * the person but not their registered mailbox needs some way in. It is
     * deliberately the weakest of the three and never the basis of the duplicate
     * warning — see {@see existing()}.
     *
     * @return Collection<int, User>
     */
    public function matching(string $term): Collection
    {
        $term = trim($term);

        if (Str::length($term) < 3) {
            /** @var Collection<int, User> $empty */
            $empty = User::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        $mobile = $this->normaliseMobile($term);

        return User::query()
            ->with(['designation', 'activeAssignment.department'])
            ->where(function ($query) use ($term, $mobile): void {
                $query->whereRaw('LOWER(email) = ?', [Str::lower($term)]);

                if ($mobile !== null) {
                    $query->orWhereRaw(
                        $this->mobileTailExpression(),
                        [$mobile],
                    );
                }

                $query->orWhere('name', 'like', $this->escapeLike($term).'%');
            })
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * The account that *is* this e-mail address or mobile number, if one exists.
     *
     * This is the "This user already exists" check, and it is stricter than
     * {@see matching()} on purpose: only an exact e-mail or an exact mobile counts.
     * Warning on a name prefix would tell an officer that *Shri R. Sahu* already
     * exists because a different *Shri R. Sahu* does, and the officer would then
     * seat the wrong person — a duplicate-prevention feature causing the more
     * serious fault it was meant to prevent.
     */
    public function existing(?string $email, ?string $mobile): ?User
    {
        $email = $email === null ? null : Str::lower(trim($email));
        $mobile = $this->normaliseMobile($mobile ?? '');

        if (($email === null || $email === '') && $mobile === null) {
            return null;
        }

        return User::query()
            ->with(['designation', 'activeAssignment.department'])
            ->where(function ($query) use ($email, $mobile): void {
                if ($email !== null && $email !== '') {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }

                if ($mobile !== null) {
                    $query->orWhereRaw($this->mobileTailExpression(), [$mobile]);
                }
            })
            ->first();
    }

    /**
     * A mobile number reduced to the ten digits that identify it, or null.
     *
     * Indian mobile numbers are ten digits; what precedes them is a country code or
     * a trunk prefix that the same person may or may not type. Anything that does
     * not end in ten digits is not a mobile number and returns null rather than a
     * truncation — matching on a fragment would pair unrelated accounts.
     */
    public function normaliseMobile(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return Str::length($digits) < 10 ? null : Str::substr($digits, -10);
    }

    /**
     * Compare the stored mobile by its last ten digits.
     *
     * Stored numbers were entered by hand over several years and are not uniformly
     * formatted, so the comparison strips the stored side too. This is a table scan
     * on `users`, which is acceptable here and would not be on a hot path: the
     * lookup runs when an officer types into one box on one screen.
     *
     * Written for both engines — SQLite runs the suite, MySQL runs the
     * application — using only `REPLACE` and `SUBSTR`, which behave identically on
     * both. `REGEXP_REPLACE` would have been shorter and is not in SQLite by
     * default.
     */
    private function mobileTailExpression(): string
    {
        $stripped = 'mobile';

        foreach ([' ', '-', '(', ')', '+', '.'] as $noise) {
            $stripped = "REPLACE({$stripped}, '{$noise}', '')";
        }

        return "SUBSTR({$stripped}, -10) = ?";
    }

    /**
     * Neutralise the wildcards a person may legitimately type into a name search.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
