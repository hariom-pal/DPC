{{-- One rule, as it reads: counts, marks, and the year-wise table for a DPC of $dpcYear. --}}
<dl class="row small mb-2">
    <dt class="col-sm-5">{{ __('admin.promotion_rules.counts') }}</dt>
    <dd class="col-sm-7">{{ __('admin.promotion_rules.counts_value', ['cr' => $rule->cr_years, 'loyalty' => $rule->loyalty_years, 'property' => $rule->property_years]) }}</dd>
    <dt class="col-sm-5">{{ __('admin.promotion_rules.qualifying_average') }}</dt>
    <dd class="col-sm-7">{{ rtrim(rtrim((string) $rule->qualifying_average, '0'), '.') }}</dd>
    <dt class="col-sm-5">{{ __('admin.promotion_rules.super_seat_grade') }}</dt>
    <dd class="col-sm-7">{{ $rule->super_seat_grade ?? __('admin.promotion_rules.none') }}</dd>
    <dt class="col-sm-5">{{ __('admin.promotion_rules.tribal_bonus') }}</dt>
    <dd class="col-sm-7">{{ $rule->tribal_bonus === 0 ? __('admin.promotion_rules.no_bonus') : $rule->tribal_bonus }}</dd>
</dl>
<div class="table-responsive">
    <table class="table table-sm small align-middle mb-0">
        <thead class="table-light"><tr>
            <th>{{ __('admin.promotion_rules.year') }}</th>
            <th class="text-center">{{ __('admin.promotion_rules.cr_min_marks') }}</th>
            <th class="text-center">{{ __('admin.promotion_rules.cr_mandatory') }}</th>
            <th class="text-center">{{ __('admin.promotion_rules.loyalty_mandatory') }}</th>
            <th class="text-center">{{ __('admin.promotion_rules.property_mandatory') }}</th>
        </tr></thead>
        <tbody>
            @foreach ($rule->years as $row)
                <tr>
                    <td>{{ __('admin.promotion_rules.year_label', ['n' => $row->years_before, 'year' => $dpcYear - $row->years_before]) }}</td>
                    <td class="text-center">{{ $row->cr_min_marks ?? '—' }}</td>
                    @foreach (['cr_mandatory' => $rule->cr_years, 'loyalty_mandatory' => $rule->loyalty_years, 'property_mandatory' => $rule->property_years] as $field => $count)
                        <td class="text-center">
                            @if ($row->years_before > $count)
                                <span class="text-muted">—</span>
                            @elseif ($row->{$field})
                                <span class="badge text-bg-primary">{{ __('admin.promotion_rules.mandatory') }}</span>
                            @else
                                <span class="badge text-bg-light border">{{ __('admin.promotion_rules.may_be_missing') }}</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
