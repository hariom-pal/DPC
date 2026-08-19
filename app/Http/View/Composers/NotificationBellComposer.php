<?php

declare(strict_types=1);

namespace App\Http\View\Composers;

use App\Domain\Authentication\Models\User;
use App\Domain\Notification\Services\NotificationCentre;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

/**
 * Supplies the header bell with its unread count and the few most recent
 * notifications — M11.
 *
 * A composer rather than a variable each controller passes, for the same reason
 * {@see ReviewDpcComposer} is one: the bell renders on **every** authenticated
 * screen, several of which belong to frozen milestones. Threading it through
 * thirty controllers would mean editing those.
 *
 * **Guarded, and that is deliberate.** The bell is chrome. If the notifications
 * table cannot be read — a migration not yet run on a deployed node, a cache
 * backend down — the page must still render. A decoration that can take down
 * every authenticated screen in the application is a worse bargain than a missing
 * badge, so a failure yields zero and no notifications rather than a 500.
 */
class NotificationBellComposer
{
    public function __construct(
        private readonly NotificationCentre $centre,
        private readonly Request $request,
    ) {}

    public function compose(View $view): void
    {
        $user = $this->request->user();

        if (! $user instanceof User || ! $this->centre->enabled()) {
            $view->with(['unreadNotificationCount' => 0, 'recentNotifications' => new Collection]);

            return;
        }

        try {
            $view->with([
                'unreadNotificationCount' => $this->centre->unreadCount($user),
                'recentNotifications' => $this->centre->recent(
                    $user,
                    (int) config('dpc.notifications.in_app.dropdown_limit', 5),
                ),
            ]);
        } catch (Throwable) {
            $view->with(['unreadNotificationCount' => 0, 'recentNotifications' => new Collection]);
        }
    }
}
