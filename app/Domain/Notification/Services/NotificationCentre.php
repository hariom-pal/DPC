<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Notification\Models\UserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reading and writing the in-app notification feed.
 *
 * The one place that writes a `UserNotification`, so the shape of a notification
 * is decided once rather than at each call site.
 */
class NotificationCentre
{
    public function __construct(private readonly NotificationRecipientResolver $recipients) {}

    public function enabled(): bool
    {
        return (bool) config('dpc.notifications.in_app.enabled', true);
    }

    /**
     * Record one notification for each resolved recipient.
     *
     * @param  list<string>  $roles
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $attributes  title, body, url, event, workflow, subject
     * @return Collection<int, UserNotification>
     */
    public function notify(?int $departmentId, array $roles, array $userIds, array $attributes): Collection
    {
        /** @var Collection<int, UserNotification> $written */
        $written = new Collection;

        if (! $this->enabled()) {
            return $written;
        }

        $recipients = $this->recipients->resolve($departmentId, $roles, $userIds);

        if ($recipients->isEmpty()) {
            return $written;
        }

        // One transaction for the whole fan-out: a half-notified transition is
        // harder to reason about than an un-notified one, and unlike SMS there is
        // no external system here that could partially succeed.
        DB::transaction(function () use ($recipients, $attributes, &$written): void {
            foreach ($recipients as $recipient) {
                $written->push(UserNotification::query()->create([
                    'user_id' => $recipient->id,
                    'subject_type' => $attributes['subject_type'] ?? null,
                    'subject_id' => $attributes['subject_id'] ?? null,
                    'workflow' => $attributes['workflow'] ?? null,
                    'event' => (string) ($attributes['event'] ?? 'notification'),
                    'title' => (string) ($attributes['title'] ?? 'Update'),
                    'body' => $attributes['body'] ?? null,
                    'url' => $attributes['url'] ?? null,
                    'occurred_at' => $attributes['occurred_at'] ?? now(),
                ]));

                // The unread count is cached per user, so a new notification has
                // to drop it — otherwise the bell stays stale for the cache
                // window on precisely the request that should have lit it up.
                $this->forgetCount($recipient);
            }
        });

        return $written;
    }

    /**
     * @return LengthAwarePaginator<int, UserNotification>
     */
    public function feed(User $user, bool $unreadOnly = false, int $perPage = 25): LengthAwarePaginator
    {
        $query = UserNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($unreadOnly) {
            $query->unread();
        }

        return $query->paginate($perPage);
    }

    /**
     * The unread count for the bell in the header.
     *
     * Cached briefly, because it is rendered on **every** authenticated page and
     * is the one query in this feature that would otherwise run hundreds of times
     * a session. The window is short enough that a new notification appears
     * promptly and long enough to stop the count being a per-request query.
     */
    public function unreadCount(User $user): int
    {
        $seconds = (int) config('dpc.notifications.in_app.unread_count_cache_seconds', 30);

        if ($seconds <= 0) {
            return $this->countUnread($user);
        }

        /** @var int $count */
        $count = cache()->remember(
            "notifications.unread.{$user->id}",
            $seconds,
            fn (): int => $this->countUnread($user),
        );

        return $count;
    }

    /**
     * The most recent few, for the header dropdown.
     *
     * @return Collection<int, UserNotification>
     */
    public function recent(User $user, int $limit = 5): Collection
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Mark one as read — only if it belongs to this user.
     *
     * Ownership is checked here rather than trusted from the route: a
     * notification names what an actor is expected to do, and reading somebody
     * else's would disclose that a DPC exists and what stage it is at.
     */
    public function markRead(User $user, UserNotification $notification): bool
    {
        if ($notification->user_id !== $user->id) {
            return false;
        }

        if ($notification->isUnread()) {
            $notification->forceFill(['read_at' => Carbon::now()])->save();
            $this->forgetCount($user);
        }

        return true;
    }

    public function markAllRead(User $user): int
    {
        $count = UserNotification::query()
            ->where('user_id', $user->id)
            ->unread()
            ->update(['read_at' => Carbon::now()]);

        $this->forgetCount($user);

        return $count;
    }

    private function countUnread(User $user): int
    {
        return UserNotification::query()->where('user_id', $user->id)->unread()->count();
    }

    private function forgetCount(User $user): void
    {
        cache()->forget("notifications.unread.{$user->id}");
    }
}
