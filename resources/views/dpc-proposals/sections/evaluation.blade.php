{{-- Evaluation / Tabulation Chart (PPT S18, reviewed at S25). Shared by the proposal detail screen and the AH review walkthrough. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">{{ __('ui.evaluation_tabulation_chart') }}</h2>
                        @include('dpc-proposals.sections._action', ['manageRoute' => 'dpc-proposals.evaluation-chart'])
                    </div>

                    @php($chartYears = $proposal->evaluationRows->flatMap(fn ($r) => $r->crGrades->pluck('year')->merge($r->loyaltyFlags->pluck('year'))->merge($r->propertyFlags->pluck('year')))->unique()->sort()->values())

                    <div class="table-responsive mt-2">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th rowspan="2">{{ __('ui.s_n') }}</th><th rowspan="2">{{ __('ui.name') }}</th><th rowspan="2">{{ __('ui.dob') }}</th><th rowspan="2">{{ __('ui.qual') }}</th><th rowspan="2" title="{{ __('ui.date_of_posting_on_promotion_from_post') }}">{{ __('ui.dop') }}</th><th rowspan="2">{{ __('ui.category') }}</th>
                                    @foreach ($chartYears as $year)<th colspan="3" class="text-center border-start">{{ $year }}</th>@endforeach
                                    <th rowspan="2" class="border-start">{{ __('ui.note') }}</th>
                                </tr>
                                <tr>
                                    @foreach ($chartYears as $year)
                                        <th class="small text-center border-start" title="{{ __('ui.year_wise_cr') }}">{{ __('dpc.sections.evaluation.cr') }}</th>
                                        <th class="small text-center" title="{{ __('ui.loyalty') }}">L</th>
                                        <th class="small text-center" title="{{ __('dpc.sections.evaluation.immovable_property_details') }}">P</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($proposal->evaluationRows as $row)
                                    <tr>
                                        <td>{{ $row->sn }}</td>
                                        <td>{{ $row->name }}</td>
                                        <td>{{ $row->dob->format('d-m-Y') }}</td>
                                        <td>{{ $row->qualification ?? '—' }}</td>
                                        <td>{{ $row->date_of_posting_on_promotion_from_post?->format('d-m-Y') ?? '—' }}</td>
                                        <td>{{ $row->reservationCategory->code }}</td>
                                        @foreach ($chartYears as $year)
                                            @php($loyalty = $row->loyaltyFlags->firstWhere('year', $year)?->flag)
                                            @php($property = $row->propertyFlags->firstWhere('year', $year)?->flag)
                                            <td class="text-center border-start">{{ $row->crGrades->firstWhere('year', $year)?->grade ?? '—' }}</td>
                                            <td class="text-center">{{ $loyalty === null ? '—' : ($loyalty ? 'Y' : 'N') }}</td>
                                            <td class="text-center">{{ $property === null ? '—' : ($property ? 'Y' : 'N') }}</td>
                                        @endforeach
                                        <td class="border-start">{{ $row->note ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ 7 + ($chartYears->count() * 3) }}" {{ __('dpc.sections.evaluation.no_entries') }}</tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <p class="text-muted small mb-0 mt-2">
                        {{ __('dpc.sections.evaluation.mark_and_remark_are_computed_by_the_commission_a') }}
                    </p>
                </div>
            </div>

            {{-- Workflow actions --}}
