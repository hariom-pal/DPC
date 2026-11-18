{{-- DPC Committee (PPT S15, reviewed at S22). Shared by the proposal detail screen and the AH review walkthrough. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">{{ __('ui.dpc_committee') }}</h2>
                        @include('dpc-proposals.sections._action', [
                            'manageRoute' => 'dpc-proposals.committee',
                            'manageLabel' => 'Manage Committee',
                        ])
                    </div>
                    @error('committee_account')<div class="alert alert-danger py-2 mt-2">{{ $message }}</div>@enderror
                    @error('reset')<div class="alert alert-danger py-2 mt-2">{{ $message }}</div>@enderror
                    <div class="table-responsive mt-2">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>{{ __('ui.name') }}</th><th>{{ __('ui.designation') }}</th><th>{{ __('ui.mobile') }}</th><th>{{ __('ui.email') }}</th><th>{{ __('dpc.committee.attributes_heading') }}</th><th>{{ __('ui.capacity') }}</th></tr></thead>
                            <tbody>
                                @forelse ($proposal->committeeMembers as $member)
                                    <tr>
                                        {{-- Built in Blade rather than as an HTML string
                                             inside a PHP ternary — see the note on the
                                             reservation-categories index for why. --}}
                                        <td>
                                            {{ $member->name }}
                                            @if ($member->isExternal())
                                                <span class="badge text-bg-light">{{ __('dpc.sections.committee.external') }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $member->designation ?? '—' }}</td>
                                        <td>{{ $member->mobile ?? '—' }}</td>
                                        <td>{{ $member->email ?? '—' }}</td>
                                        {{-- Item 8: what this member represents on
                                             *this* committee.

                                             The social category is always stated,
                                             never blank (business clarification,
                                             2026-09-05): a member who ticked none
                                             of SC/ST/OBC is UR (General), and a
                                             dash there would read as "not
                                             recorded" rather than as the answer.
                                             The additional attributes follow, and
                                             those may genuinely be none. --}}
                                        <td class="small">
                                            {{ $member->socialCategoryLabel() }}
                                            @if ($member->additionalAttributeLabel())
                                                <span class="text-body-secondary">/ {{ $member->additionalAttributeLabel() }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge {{ $member->isCgpscChairman() ? 'text-bg-primary' : 'text-bg-secondary' }}">{{ $member->capacity->label() }}</span>
                                            @if ($member->isDpcChairman())
                                                <span class="badge text-bg-info">{{ __('dpc.committee.dpc_chairman_badge') }}</span>
                                            @endif

                                            {{-- Who presides is no longer designated here: the person the DPC
                                                 is allotted to is its DPC Chairman (business instruction, 2026-11-17). --}}

                                            {{-- Item 9e: the Administrator may substitute a member at
                                                 **any** stage, so this is not gated on the proposal being
                                                 editable — a member transferred between the committee
                                                 being recorded and the sitting being held is an ordinary
                                                 event, and refusing it after submission would leave the
                                                 department unable to record what actually happened. --}}
                                            @if (auth()->user()?->hasRole(\App\Enums\Role::Admin->value))
                                                <a class="btn btn-link btn-sm p-0 align-baseline"
                                                   href="{{ route('admin.committee-members.replace.form', [$proposal, $member]) }}">
                                                    {{ __('dpc.committee.replace_member') }}
                                                </a>
                                            @endif
                                            {{-- The member's login (2026-11-18), the Administrator's: its state,
                                                 and Create Login / Link Account or a reset the member completes. --}}
                                            @if (auth()->user()?->hasRole(\App\Enums\Role::Admin->value))
                                                <div class="mt-1" data-member-account="{{ $member->id }}">
                                                    @if ($member->user)
                                                        <span class="badge text-bg-success">{{ __('dpc.committee.account.linked') }}</span>
                                                        @if ($member->user->activeAssignment?->department)
                                                            <span class="text-muted">{{ $member->user->activeAssignment->department->name }}</span>
                                                        @endif
                                                        <form method="POST" class="d-inline" action="{{ route('admin.committee-members.reset-password', [$proposal, $member]) }}"
                                                              data-confirm="{{ __('dpc.committee.account.reset_confirm') }}">
                                                            @csrf
                                                            <button class="btn btn-link btn-sm p-0 align-baseline" data-member-reset="{{ $member->id }}">{{ __('admin.users.index.reset_password') }}</button>
                                                        </form>
                                                    @else
                                                        <span class="badge text-bg-warning">{{ __('dpc.committee.account.none') }}</span>
                                                        <form method="POST" class="d-inline" action="{{ route('admin.committee-members.account', [$proposal, $member]) }}">
                                                            @csrf
                                                            <button class="btn btn-link btn-sm p-0 align-baseline" data-member-create-login="{{ $member->id }}">{{ __('dpc.committee.account.create') }}</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-3">{{ __('dpc.sections.committee.no_committee_members_yet') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

