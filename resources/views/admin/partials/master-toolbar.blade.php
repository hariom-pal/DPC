{{--
    Search · Export CSV · Bulk upload — the three things every reference-master
    screen carries since 2026-09-06.

    One partial rather than the same block pasted onto twenty screens: the three
    features are identical everywhere and differ only in which master they act on,
    which arrives as `$master` — the key `App\Domain\Master\Support\MasterRegistry`
    knows it by.

    Include it as:

        @include('admin.partials.master-toolbar', ['master' => 'qualifications'])

    Everything degrades: the search is a plain GET form, the two downloads are
    links, and the upload is an ordinary multipart POST. Nothing here needs
    JavaScript, which matters because the Content-Security-Policy forbids inline
    script and this partial appears on every master screen.
--}}
@php
    $definition = \App\Domain\Master\Support\MasterRegistry::find($master);
@endphp

@if ($definition !== null)
    <div class="d-flex flex-wrap gap-2 align-items-start mb-3">
        {{-- Search. A GET so the result is a URL somebody can bookmark, send, or
             reload after an edit — and so Export can honour the same `?q=`. --}}
        <form method="GET" class="d-flex gap-2 flex-grow-1" style="max-width:28rem" role="search">
            <input type="search" class="form-control form-control-sm" name="q"
                   value="{{ request('q') }}"
                   placeholder="{{ __('admin.master_data.search_placeholder', ['master' => $definition->label]) }}"
                   aria-label="{{ __('admin.master_data.search_placeholder', ['master' => $definition->label]) }}">
            <button class="btn btn-sm btn-outline-primary text-nowrap">{{ __('ui.search') }}</button>
            @if (request()->filled('q'))
                <a class="btn btn-sm btn-link text-nowrap" href="{{ url()->current() }}">{{ __('ui.clear') }}</a>
            @endif
        </form>

        <div class="ms-auto d-flex flex-wrap gap-2">
            {{-- Carries the current `?q=`, so "export" means "export what I am
                 looking at" rather than "export everything". --}}
            <a class="btn btn-sm btn-outline-secondary text-nowrap"
               href="{{ route('admin.master-data.export', ['master' => $master, 'q' => request('q')]) }}">
                {{ __('admin.master_data.export_csv') }}
            </a>
            <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                    data-bs-toggle="modal" data-bs-target="#bulk-upload-{{ $master }}">
                {{ __('admin.master_data.bulk_upload') }}
            </button>
        </div>
    </div>

    @error('file')
        <div class="alert alert-danger py-2">
            <strong>{{ __('admin.master_data.upload_refused') }}</strong>
            <ul class="mb-0 small">
                @foreach ($errors->get('file') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @enderror

    <div class="modal fade" id="bulk-upload-{{ $master }}" tabindex="-1"
         aria-labelledby="bulk-upload-{{ $master }}-title" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="POST" enctype="multipart/form-data"
                  action="{{ route('admin.master-data.import', $master) }}">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title h6" id="bulk-upload-{{ $master }}-title">
                        {{ __('admin.master_data.bulk_upload_title', ['master' => $definition->label]) }}
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="{{ __('ui.close') }}"></button>
                </div>
                <div class="modal-body">
                    <ol class="small ps-3 mb-3">
                        <li>
                            {{ __('admin.master_data.step_download') }}
                            <a href="{{ route('admin.master-data.template', $master) }}">{{ __('admin.master_data.sample_csv') }}</a>
                        </li>
                        <li>{{ __('admin.master_data.step_fill') }}</li>
                        <li>{{ __('admin.master_data.step_upload') }}</li>
                    </ol>

                    {{-- Said before the upload, not after it. An officer who expects
                         a replace and gets a merge will not notice until a
                         withdrawn row turns up in a dropdown weeks later. --}}
                    <div class="alert alert-secondary py-2 small mb-3">
                        {{ __('admin.master_data.merge_note') }}
                    </div>

                    <label class="form-label" for="file-{{ $master }}">{{ __('admin.master_data.choose_file') }}</label>
                    <input type="file" class="form-control" id="file-{{ $master }}" name="file" accept=".csv,text/csv" required>
                    <div class="form-text">{{ __('admin.master_data.columns', ['columns' => implode(', ', $definition->headers())]) }}</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">{{ __('ui.cancel') }}</button>
                    <button class="btn btn-primary btn-sm">{{ __('admin.master_data.upload') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif
