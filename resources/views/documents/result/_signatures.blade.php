{{-- The committee's signature blocks (2026-11-25): each member under their name; on
     the final copy, with their portal sign-off (never a certificate-based digital signature). Expects: $members, $signatures (user_id => [at]). --}}
<table class="signs">
    @foreach (array_chunk($members, 3) as $chunk)
        <tr>
            @foreach ($chunk as $member)
                <td>
                    @php($signed = $member['user_id'] !== null ? ($signatures[$member['user_id']] ?? null) : null)
                    @if ($signed)
                        <div class="esign">पोर्टल पर हस्ताक्षरित<br>{{ $signed['at'] }}</div>
                    @else
                        <div class="esign-blank">&nbsp;</div>
                    @endif
                    ({{ $member['name'] }})<br>
                    {{ $member['chairman'] ? 'अध्यक्ष' : 'सदस्य' }}@if ($member['designation'] !== '')/{{ $member['designation'] }}@endif
                    @foreach ($member['represents'] as $represents)<br>({{ $represents }})@endforeach
                </td>
            @endforeach
            @for ($i = count($chunk); $i < 3; $i++)<td></td>@endfor
        </tr>
    @endforeach
</table>
