<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who should be told.
 *
 * **This is TD-20's resolver, created where it can be created.** TD-20 records
 * that `SendWorkflowNotification` and `SendDocumentPublicationNotification` hold
 * two copies of this query and that extracting a shared one is a functional change
 * to frozen M6, so it waits for a freeze window. Adding a *third* copy for the
 * in-app channel would have made that worse.
 *
 * So the resolver exists now and the new channel uses it. **The two frozen copies
 * are untouched.** When the freeze next opens, TD-20's fix is no longer "write a
 * resolver and repoint two callers" — it is "repoint two callers", because the
 * resolver is already here and already exercised.
 *
 * **Two independent recipient sets, and the second is not an optimisation.**
 * Role-within-department cannot reach a CGPSC Chairman or Member, who hold no
 * department at all, so specific user ids are named outright. Dropping either set
 * silently loses a class of recipient.
 */
class NotificationRecipientResolver
{
    /**
     * @param  list<string>  $roles  department-scoped roles
     * @param  list<int>  $userIds  specific users, regardless of department
     * @return Collection<int, User>
     */
    public function resolve(?int $departmentId, array $roles, array $userIds = []): Collection
    {
        if (($departmentId === null || $roles === []) && $userIds === []) {
            /** @var Collection<int, User> $empty */
            $empty = User::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        return User::query()
            // Never a deactivated account: notifying one is noise nobody reads,
            // and for the in-app channel it would sit unread for ever.
            ->where('is_active', true)
            ->where(function (Builder $query) use ($departmentId, $roles, $userIds): void {
                if ($departmentId !== null && $roles !== []) {
                    $query->orWhere(fn (Builder $scoped): Builder => $scoped
                        ->where('department_id', $departmentId)
                        ->role($roles));
                }

                if ($userIds !== []) {
                    $query->orWhereIn('id', $userIds);
                }
            })
            ->get();
    }
}
