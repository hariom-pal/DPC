<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Application roles (RBAC via spatie/laravel-permission).
 *
 * Derived from the PPT actors: Authorized Officer, Administrative Head, CGPSC
 * Chairman, CGPSC Member, and the individual under consideration.
 *
 * **There is exactly one administrator role, and it belongs to the Commission**
 * (change item 1, 2026-08-24). The register settled that the CGPSC Administrator
 * owns every master — departments, department posts, users, role assignments,
 * recruitment rules, pay scales, classes, vacancy types, committee masters — and
 * that a *Department Administrator* does not exist. It never did in this
 * codebase; the ruling makes that permanent, and the label below now says
 * whose administrator this is.
 *
 * A role is granted in one of two ways, and which one depends on whether the
 * role is departmental:
 *
 *  - **Departmental** roles ({@see departmental()}) are granted by a dated
 *    assignment — see `App\Domain\UserManagement\Models\UserDepartmentAssignment`.
 *    The assignment is the source of truth; the Spatie grant is synced from it.
 *  - **Commission** roles hold no department, so they are plain Spatie grants
 *    made by the Administrator.
 */
enum Role: string
{
    case Admin = 'admin';
    case AuthorizedOfficer = 'authorized_officer';
    case AdministrativeHead = 'administrative_head';
    case Chairman = 'chairman';
    case Member = 'member';
    case Individual = 'individual';

    /**
     * Every officer named on a Seniority List (change item 17).
     *
     * Distinct from `Individual`, which is the *unauthenticated* self-inspection
     * actor of S49–S51 — identified by facts about themselves rather than by
     * credentials, and holding no account at all. A Promotion Candidate holds a
     * real login and sees their own DPC, status, timeline, notifications and
     * published result, and nothing about any other candidate.
     */
    case PromotionCandidate = 'promotion_candidate';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'CGPSC Administrator',
            self::AuthorizedOfficer => 'Authorized Officer',
            self::AdministrativeHead => 'Administrative Head',
            self::Chairman => 'Chairman, CGPSC',
            self::Member => 'Member, CGPSC',
            self::Individual => 'Individual under Consideration',
            self::PromotionCandidate => 'Promotion Candidate',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /**
     * Roles that exist *within a department*, and therefore only ever through an
     * assignment.
     *
     * A Promotion Candidate is departmental: the officer belongs to the
     * department whose Seniority List names them, and if they transfer, their
     * candidature travels the same way an officer's posting does.
     *
     * @return list<self>
     */
    public static function departmental(): array
    {
        return [self::AuthorizedOfficer, self::AdministrativeHead, self::PromotionCandidate];
    }

    /**
     * @return list<string>
     */
    public static function departmentalValues(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::departmental());
    }

    public function isDepartmental(): bool
    {
        return in_array($this, self::departmental(), true);
    }

    /**
     * Roles the CGPSC Administrator provisions as an account outright.
     *
     * `Individual` is absent because it holds no account. `PromotionCandidate` is
     * absent because the account is created by the Seniority List that names the
     * officer, never typed in by hand — see
     * `App\Domain\UserManagement\Services\PromotionCandidateProvisioner`.
     *
     * The two department roles *are* present: item 1 puts Users and Role
     * Assignments under the Administrator, so the Administrator can post an
     * officer to a department directly rather than only via S6 registration.
     *
     * @return list<self>
     */
    public static function provisionable(): array
    {
        return [self::Admin, self::Chairman, self::Member, self::AuthorizedOfficer, self::AdministrativeHead];
    }

    /**
     * @return list<string>
     */
    public static function provisionableValues(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::provisionable());
    }
}
