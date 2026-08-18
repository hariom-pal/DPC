@extends('layouts.admin')

@section('title', 'Recruitment Rules')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Recruitment Rules / भर्ती नियम</h1>
        <a href="{{ route('admin.recruitment-rules.create') }}" class="btn btn-success btn-sm">+ New Rule</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Cadre</th><th>Ver.</th><th>GO No.</th><th>Feeder Cadres → Min. Qualification</th><th>Effective</th>
                        <th>Direct %</th><th>Promo %</th><th>GO PDF</th><th>Status</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rules as $rule)
                        <tr>
                            <td>{{ $rule->cadre?->name }}</td>
                            <td>v{{ $rule->version }}</td>
                            <td>{{ $rule->government_order_number }}</td>
                            <td class="small">
                                @forelse ($rule->feeders as $feeder)
                                    <div>
                                        {{ $feeder->feederCadre?->name ?? '—' }}
                                        <span class="text-body-secondary">
                                            · {{ rtrim(rtrim(number_format((float) $feeder->promotion_percentage, 2, '.', ''), '0'), '.') }}% share
                                            @if ($feeder->experienceLabel())
                                                · {{ $feeder->experienceLabel() }}
                                            @endif
                                            @if ($feeder->qualificationLabel())
                                                · {{ $feeder->qualificationLabel() }}
                                                @unless ($feeder->qualification_id)
                                                    {{-- Legacy free text that matched no master row. --}}
                                                    <span class="badge text-bg-warning" title="Not linked to the Qualification master">unmapped</span>
                                                @endunless
                                            @endif
                                        </span>
                                    </div>
                                @empty
                                    <span class="text-body-secondary">Direct recruitment only</span>
                                @endforelse
                            </td>
                            <td>{{ $rule->effective_from?->format('d-m-Y') }} — {{ $rule->effective_to?->format('d-m-Y') ?? 'current' }}</td>
                            <td>{{ $rule->direct_recruitment_pct }}</td>
                            <td>{{ $rule->promotion_recruitment_pct }}</td>
                            <td>
                                @if ($rule->go_document_path)
                                    <a href="{{ route('admin.recruitment-rules.download', $rule) }}">PDF</a>
                                @else — @endif
                            </td>
                            <td><x-status-badge :status="$rule->status" /></td>
                            <td class="text-end text-nowrap">
                                @if ($rule->status->value === 'draft')
                                    <a href="{{ route('admin.recruitment-rules.edit', $rule) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                @endif
                                <form method="POST" action="{{ route('admin.recruitment-rules.version', $rule) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-outline-secondary btn-sm">New Version</button>
                                </form>
                                <form method="POST" action="{{ route('admin.recruitment-rules.destroy', $rule) }}" class="d-inline"
                                      onsubmit="return confirm('Delete this rule version?');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">No recruitment rules yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $rules->links() }}</div>
@endsection
