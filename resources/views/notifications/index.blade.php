@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 class="h4 mb-1">Notifications</h1>
                    <p class="text-muted small mb-0">
                        Everything the system has told you, kept in the application.
                        @if ($unreadCount > 0)
                            <strong>{{ $unreadCount }} unread.</strong>
                        @endif
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('notifications.index', $unreadOnly ? [] : ['unread' => 1]) }}"
                       class="btn btn-sm {{ $unreadOnly ? 'btn-primary' : 'btn-outline-primary' }}">
                        {{ $unreadOnly ? 'Showing unread' : 'Unread only' }}
                    </a>
                    @if ($unreadCount > 0)
                        <form method="POST" action="{{ route('notifications.read-all') }}" class="m-0">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">Mark all read</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <ul class="list-group list-group-flush">
                    @forelse ($notifications as $notification)
                        <li class="list-group-item d-flex gap-3 align-items-start
                                   {{ $notification->isUnread() ? 'bg-body-tertiary' : '' }}">
                            <span class="gov-icon flex-shrink-0 mt-1">
                                @include('partials.icon', ['name' => 'bell', 'size' => 18])
                            </span>

                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap gap-2 align-items-baseline">
                                    <span class="{{ $notification->isUnread() ? 'fw-semibold' : '' }}">
                                        {{ $notification->title }}
                                    </span>
                                    @if ($notification->isUnread())
                                        <span class="badge text-bg-primary">New</span>
                                    @endif
                                </div>

                                {{-- The mechanical detail, kept because the title is
                                     a paraphrase and somebody will need to know
                                     exactly which transition fired. --}}
                                <div class="small text-body-secondary">{{ $notification->body }}</div>

                                <div class="small text-body-secondary">
                                    {{ $notification->occurred_at->format('d-m-Y H:i') }}
                                    · {{ $notification->occurred_at->diffForHumans() }}
                                </div>
                            </div>

                            <form method="POST" action="{{ route('notifications.read', $notification) }}" class="m-0">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary">
                                    {{ $notification->url ? 'Open' : 'Mark read' }}
                                </button>
                            </form>
                        </li>
                    @empty
                        <li class="list-group-item text-muted text-center py-5">
                            {{ $unreadOnly ? 'Nothing unread.' : 'You have no notifications yet.' }}
                        </li>
                    @endforelse
                </ul>
            </div>

            <div class="mt-3">{{ $notifications->links() }}</div>

            {{-- Said plainly, because an actor who sees a notification here and
                 no SMS will otherwise assume something is broken. --}}
            <p class="small text-muted mt-3 mb-0">
                Notifications are also sent by SMS and e-mail once those gateways are
                connected. This list does not depend on them.
            </p>
        </div>
    </div>
@endsection
