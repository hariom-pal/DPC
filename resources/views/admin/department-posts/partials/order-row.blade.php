{{--
    One Government Order on a Department Post Mapping (change register of
    2026-08-26, item 10).

    `$index` is a row number rather than an id: these rows are *new* orders being
    recorded, and `__I__` in the template is substituted by the Add More button.
    Existing orders are never rendered here — they are evidence and cannot be
    edited, so the form lists them read-only above this repeater.

    An empty row means "no further order", which is why nothing here is `required`.
    The request pairs the number and the date, so giving one without the other is
    what gets refused.
--}}
<div class="row g-2 align-items-start government-order-row mb-2">
    <div class="col-md-4">
        <label class="form-label small" for="order-{{ $index }}-number">
            {{ __('admin.department_posts.form.go_number') }}
        </label>
        <input type="text"
               class="form-control form-control-sm @error("orders.{$index}.government_order_number") is-invalid @enderror"
               id="order-{{ $index }}-number"
               name="orders[{{ $index }}][government_order_number]"
               value="{{ $row['government_order_number'] ?? '' }}">
        @error("orders.{$index}.government_order_number")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-3">
        <label class="form-label small" for="order-{{ $index }}-date">
            {{ __('admin.department_posts.form.go_date') }}
        </label>
        <input type="date"
               class="form-control form-control-sm @error("orders.{$index}.government_order_date") is-invalid @enderror"
               id="order-{{ $index }}-date"
               name="orders[{{ $index }}][government_order_date]"
               value="{{ $row['government_order_date'] ?? '' }}">
        @error("orders.{$index}.government_order_date")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label small" for="order-{{ $index }}-document">
            {{ __('admin.department_posts.form.go_document') }}
        </label>
        {{-- A file input cannot be repopulated from `old()` — the browser will not
             let a page set one — so a submission that fails validation loses the
             chosen file and the Administrator picks it again. Saying so is better
             than appearing to have kept it. --}}
        <input type="file" accept="application/pdf"
               class="form-control form-control-sm @error("orders.{$index}.document") is-invalid @enderror"
               id="order-{{ $index }}-document"
               name="orders[{{ $index }}][document]">
        @error("orders.{$index}.document")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
        <div class="form-text">{{ __('admin.department_posts.form.hint_go_document') }}</div>
    </div>

    <div class="col-md-1 d-flex align-items-end">
        <button type="button" class="btn btn-outline-danger btn-sm remove-government-order"
                aria-label="{{ __('admin.department_posts.form.remove_order') }}">{{ __('ui.remove') }}</button>
    </div>
</div>
