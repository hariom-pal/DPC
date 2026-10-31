{{-- The notification bell — M11.

     Supplied by NotificationBellComposer on `layouts.app`, so it reaches every
     authenticated screen without any of them being edited — several belong to
     frozen milestones.

     $unreadNotificationCount and $recentNotifications are always set by the
     composer, including when notifications are disabled or unreadable, so this
     partial never has to guess. Rendered only when there is something to show or
     a count to report; a permanently empty bell is clutter. --}}

@php($unread = $unreadNotificationCount ?? 0)
@php($recent = $recentNotifications ?? collect())

<div class="dropdown">
    <button class="btn btn-sm btn-outline-light position-relative" type="button"
            data-bs-toggle="dropdown" data-bs-auto-close="outside"
            aria-expanded="false"
            aria-label="{{ __('ui.notifications') }}{{ $unread > 0 ? ' — '.__('ui.screen.unread_count', ['count' => $unread]) : '' }}">
        @include('partials.icon', ['name' => 'bell', 'size' => 16])
        @if ($unread > 0)
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger">
                {{ $unread > 99 ? '99+' : $unread }}
                <span class="visually-hidden">{{ __('ui.partials.notification_bell.unread_notifications') }}</span>
            </span>
        @endif
    </button>

    <div class="dropdown-menu dropdown-menu-end shadow" style="min-width:22rem;max-width:26rem">
        <h6 class="dropdown-header d-flex justify-content-between align-items-center">
            <span>{{ __('ui.notifications') }}</span>
            @if ($unread > 0)
                {!! __('ui.partials.notification_bell.n_unread', ['count' => $unread]) !!}
            @endif
        </h6>

        @forelse ($recent as $notification)
            <form method="POST" action="{{ route('notifications.read', $notification) }}" class="m-0" data-no-busy>
                @csrf
                {{-- Marking read and going where it points are one action: a
                     notification that must be dismissed separately from being
                     acted on is one that stays unread for ever. --}}
                <button type="submit"
                        class="dropdown-item text-wrap py-2 {{ $notification->isUnread() ? 'fw-semibold' : '' }}">
                    <span class="d-block small">{{ $notification->title }}</span>
                    <span class="d-block text-body-secondary" style="font-size:.75rem">
                        {{ $notification->occurred_at->diffForHumans() }}
                    </span>
                </button>
            </form>
        @empty
            <span class="dropdown-item-text text-body-secondary small">{{ __('ui.partials.notification_bell.nothing_yet') }}</span>
        @endforelse

        <div class="dropdown-divider"></div>

        <div class="d-flex gap-2 px-3 pb-2">
            <a href="{{ route('notifications.index') }}" class="btn btn-sm btn-outline-primary flex-fill">
                {{ __('ui.partials.notification_bell.see_all') }}
            </a>
            @if ($unread > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}" class="m-0 flex-fill" data-no-busy>
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary w-100">{{ __('ui.mark_all_read') }}</button>
                </form>
            @endif
        </div>
    </div>
</div>
