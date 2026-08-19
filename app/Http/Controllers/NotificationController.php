<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Notification\Models\UserNotification;
use App\Domain\Notification\Services\NotificationCentre;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The notification feed — M11.
 *
 * Every actor sees only their own. Ownership is enforced in
 * {@see NotificationCentre::markRead()} rather than by route scoping, because a
 * notification names what somebody is expected to do: reading another actor's
 * would disclose that a DPC exists and what stage it has reached.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationCentre $centre) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $unreadOnly = $request->boolean('unread');

        return view('notifications.index', [
            'notifications' => $this->centre->feed($user, $unreadOnly)->appends($request->only('unread')),
            'unreadOnly' => $unreadOnly,
            'unreadCount' => $this->centre->unreadCount($user),
        ]);
    }

    /**
     * Mark one read and go where it points.
     *
     * Marking and following are one action rather than two: a notification that
     * has to be dismissed separately from being acted on is one that stays unread
     * for ever.
     */
    public function read(Request $request, UserNotification $notification): RedirectResponse
    {
        abort_unless($this->centre->markRead($request->user(), $notification), 403);

        $target = $notification->url;

        // Only a path within this application. A stored absolute URL would make
        // this endpoint an open redirect, and it is reached from an e-mail-shaped
        // context where that matters.
        if (is_string($target) && str_starts_with($target, '/')) {
            return redirect()->to($target);
        }

        return redirect()->route('notifications.index');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $count = $this->centre->markAllRead($request->user());

        return redirect()->route('notifications.index')
            ->with('status', $count === 0
                ? 'You had nothing unread.'
                : "{$count} notification(s) marked as read.");
    }
}
