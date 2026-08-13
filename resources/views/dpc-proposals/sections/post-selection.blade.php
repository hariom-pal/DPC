{{-- Selection of Posts (PPT S14, reviewed at S21). Shared by the proposal detail screen and the AH review walkthrough. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">Post Selection</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Type of DPC</dt><dd class="col-sm-9">{{ $proposal->dpcType->name_en }}</dd>
                        <dt class="col-sm-3">Post From</dt><dd class="col-sm-9">{{ $proposal->postFromCadre->name }} (Class {{ $proposal->postFromCadre->class_level->value }})</dd>
                        <dt class="col-sm-3">Post To</dt><dd class="col-sm-9">{{ $proposal->postToCadre->name }} (Class {{ $proposal->postToCadre->class_level->value }})</dd>
                        <dt class="col-sm-3">Proposal Date</dt><dd class="col-sm-9">{{ $proposal->proposal_date->format('d-m-Y') }}</dd>
                        <dt class="col-sm-3">Legal Stay</dt><dd class="col-sm-9">{{ $proposal->legal_stay ? 'Yes' : 'No' }}</dd>
                        @if ($proposal->dpc_code)
                            <dt class="col-sm-3">DPC ID</dt><dd class="col-sm-9"><strong>{{ $proposal->dpc_code }}</strong></dd>
                        @endif
                        @if ($proposal->approved_at)
                            <dt class="col-sm-3">Approved</dt>
                            <dd class="col-sm-9">{{ $proposal->approved_at->format('d-m-Y H:i') }} by {{ $proposal->approver?->name }} (signature #{{ $proposal->signature_id }}), forwarded to CGPSC {{ $proposal->forwarded_at?->format('d-m-Y H:i') }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

