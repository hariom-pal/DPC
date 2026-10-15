{{-- Selection of Posts (PPT S14, reviewed at S21). Shared by the proposal detail screen and the AH review walkthrough. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ __('dpc.sections.post_selection.post_selection') }}</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-3">{{ __('ui.type_of_dpc') }}</dt><dd class="col-sm-9">{{ $proposal->dpcType->name_en }}</dd>
                        {{-- Each post with **its own** Class and Pay Scale.

                             Eligibility for this promotion is judged on the officer's
                             current post, so the feeder's figures are the ones a
                             reader checks a candidate against — and they are read
                             from that post's own Department Post Mapping rather than
                             from the proposal, which carries the promotion post's. --}}
                        <dt class="col-sm-3">{{ __('ui.post_from') }}</dt>
                        <dd class="col-sm-9">
                            {{ __('ui.post_with_class', ['post' => $proposal->promotionFromPost->name, 'class' => $proposal->promotionFromPost->class_level->value]) }}
                            @if ($proposal->promotionFromPost->payScale)
                                — {{ $proposal->promotionFromPost->payScale->label }}
                            @endif
                        </dd>
                        <dt class="col-sm-3">{{ __('ui.post_to') }}</dt>
                        <dd class="col-sm-9">
                            {{ __('ui.post_with_class', ['post' => $proposal->promotionToPost->name, 'class' => $proposal->promotionToPost->class_level->value]) }}
                            @if ($proposal->payScale) — {{ $proposal->payScale->label }} @endif
                        </dd>
                        <dt class="col-sm-3">{{ __('ui.dpc_year') }}</dt><dd class="col-sm-9">{{ $proposal->dpc_year ?? '—' }}</dd>
                        <dt class="col-sm-3">{{ __('ui.promotion_duration') }}</dt>
                        <dd class="col-sm-9">{{ $proposal->promotion_duration_years === null ? '—' : __('ui.n_years', ['count' => $proposal->promotion_duration_years]) }}</dd>
                        @if ($proposal->preDpcProposal)
                            <dt class="col-sm-3">{{ __('dpc.sections.post_selection.sanctioned_by') }}</dt>
                            <dd class="col-sm-9">
                                {{ $proposal->preDpcProposal->pre_dpc_number }}
                                {{ __('dpc.sections.post_selection.pre_dpc_go', ['go' => $proposal->preDpcProposal->government_order_number ?? __('dpc.sections.post_selection.go_not_recorded')]) }}
                            </dd>
                        @endif
                        {{-- Generated, never typed (change item 12). --}}
                        <dt class="col-sm-3">{{ __('ui.proposal_date') }}</dt><dd class="col-sm-9">{{ $proposal->proposal_date->format('d-m-Y') }}</dd>
                        <dt class="col-sm-3">{{ __('dpc.sections.post_selection.legal_stay') }}</dt><dd class="col-sm-9">{{ $proposal->legal_stay ? __('ui.yes') : __('ui.no') }}</dd>
                        {{-- The number is issued at creation and never changes; the
                             revision is which attempt at this proposal is being read
                             (change item 18). --}}
                        <dt class="col-sm-3">{{ __('dpc.sections.post_selection.dpc_number') }}</dt>
                        <dd class="col-sm-9"><strong>{{ $proposal->dpc_code ?? '—' }}</strong></dd>
                        <dt class="col-sm-3">{{ __('dpc.sections.post_selection.revision') }}</dt>
                        <dd class="col-sm-9">{{ $proposal->revision_number }}</dd>
                        @if ($proposal->approved_at)
                            <dt class="col-sm-3">{{ __('ui.approved') }}</dt>
                            <dd class="col-sm-9">{{ __('dpc.sections.post_selection.approved_and_forwarded', [
                                'at' => $proposal->approved_at->format('d-m-Y H:i'),
                                'actor' => $proposal->approver?->name,
                                'id' => $proposal->signature_id,
                                'forwarded' => $proposal->forwarded_at?->format('d-m-Y H:i'),
                            ]) }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

