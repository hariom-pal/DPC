{{-- Evaluation / Tabulation Chart (PPT S18, reviewed at S25). Shared by the proposal detail screen and the AH review walkthrough. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">Evaluation / Tabulation Chart</h2>
                        @if ($proposal->status->isEditable() && $isAO)
                            <a href="{{ route('dpc-proposals.evaluation-chart', $proposal) }}" class="btn btn-outline-primary btn-sm">Manage</a>
                        @endif
                    </div>

                    @php($chartYears = $proposal->evaluationRows->flatMap(fn ($r) => $r->crGrades->pluck('year')->merge($r->loyaltyFlags->pluck('year'))->merge($r->propertyFlags->pluck('year')))->unique()->sort()->values())

                    <div class="table-responsive mt-2">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th rowspan="2">S.N.</th><th rowspan="2">Name</th><th rowspan="2">DOB</th><th rowspan="2">Qual.</th><th rowspan="2" title="Date of Posting on Feeder Post">DOP</th><th rowspan="2">Category</th>
                                    @foreach ($chartYears as $year)<th colspan="3" class="text-center border-start">{{ $year }}</th>@endforeach
                                    <th rowspan="2" class="border-start">Note</th>
                                </tr>
                                <tr>
                                    @foreach ($chartYears as $year)
                                        <th class="small text-center border-start" title="Year-wise CR">CR</th>
                                        <th class="small text-center" title="Loyalty">L</th>
                                        <th class="small text-center" title="Immovable Property Details">P</th>
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
                                        <td>{{ $row->date_of_posting_on_feeder_post?->format('d-m-Y') ?? '—' }}</td>
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
                                    <tr><td colspan="{{ 7 + ($chartYears->count() * 3) }}" class="text-center text-muted py-2">No evaluation chart entries yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <p class="text-muted small mb-0 mt-2">
                        Mark and Remark are computed by the Commission at CALCULATE time and are not part of this proposal.
                    </p>
                </div>
            </div>

            {{-- Workflow actions --}}
