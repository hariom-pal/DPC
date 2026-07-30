@extends('layouts.app')

@section('title', 'Dashboard — ' . __('messages.app_name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Welcome, {{ $user->name }}</h1>
                    <p class="text-muted mb-3">{{ $user->designation?->name }}</p>

                    <dl class="row mb-0">
                        <dt class="col-sm-3">Role</dt>
                        <dd class="col-sm-9">
                            @foreach ($user->getRoleNames() as $role)
                                <span class="badge text-bg-secondary">{{ $role }}</span>
                            @endforeach
                        </dd>
                        @if ($user->department)
                            <dt class="col-sm-3">Department</dt>
                            <dd class="col-sm-9">{{ $user->department->name }}</dd>
                        @endif
                        <dt class="col-sm-3">Mobile</dt>
                        <dd class="col-sm-9">{{ $user->mobile }}</dd>
                    </dl>
                </div>
            </div>

            <p class="text-muted small mt-3">
                Modules become available as the corresponding milestones are delivered.
            </p>
        </div>
    </div>
@endsection
