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

                                            {{-- Item 9, as clarified: the CGPSC Chairman names who
                                                 conducts this DPC, choosing from this committee — which
                                                 includes their own seat, so taking it themselves is the
                                                 same control rather than a special case.

                                                 Shown only to the CGPSC Chairman, and only once the DPC
                                                 has reached the Commission: designating a conductor for a
                                                 proposal still being drafted in a department would be
                                                 acting before there is anything to conduct. --}}
                                            @if (! $member->isDpcChairman()
                                                && auth()->user()?->hasRole(\App\Enums\Role::Chairman->value)
                                                && $proposal->forwarded_at !== null)
                                                <form method="POST" class="d-inline"
                                                      action="{{ route('cgpsc.committee.designate-chairman', [$proposal, $member]) }}">
                                                    @csrf
                                                    <button class="btn btn-link btn-sm p-0 align-baseline">
                                                        {{ __('dpc.committee.designate_chairman') }}
                                                    </button>
                                                </form>
                                            @endif

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

