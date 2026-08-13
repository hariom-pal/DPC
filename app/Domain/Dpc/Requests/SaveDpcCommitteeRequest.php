<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Master\Models\CommitteeConfiguration;
use App\Enums\MasterStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * DPC Committee (PPT S15) — M4 Phase 2. All composition rules are enforced
 * server-side (architect directive, 2026-08-13): exactly one Chairman,
 * Chairman mandatory, no duplicate members (same user twice, or two
 * entries sharing a mobile number), and the total size must satisfy the
 * active `CommitteeConfiguration` for the `dpc_committee` type.
 */
class SaveDpcCommitteeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'members' => ['required', 'array', 'max:'.(int) config('dpc.limits.committee_members')],
            'members.*.user_id' => ['nullable', Rule::exists('users', 'id')],
            'members.*.name' => ['required_without:members.*.user_id', 'nullable', 'string', 'max:255'],
            'members.*.designation' => ['nullable', 'string', 'max:255'],
            'members.*.mobile' => ['nullable', 'string', 'max:20'],
            'members.*.email' => ['nullable', 'email', 'max:255'],
            'members.*.capacity' => ['required', new Enum(CommitteeCapacity::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $rawMembers */
            $rawMembers = $this->input('members', []);
            $members = collect($rawMembers);

            if ($members->isEmpty()) {
                return;
            }

            $config = $this->activeConfiguration();

            $chairmanCount = $members->where('capacity', CommitteeCapacity::Chairman->value)->count();

            if ($chairmanCount !== 1) {
                $validator->errors()->add('members', 'The committee must have exactly one Chairman.');
            }

            $userIds = $members->pluck('user_id')->filter()->values();
            if ($userIds->count() !== $userIds->unique()->count()) {
                $validator->errors()->add('members', 'The same user cannot appear twice in the committee.');
            }

            $mobiles = $members->pluck('mobile')->filter()->map(fn ($m) => trim((string) $m))->filter(fn ($m) => $m !== '');
            if ($mobiles->count() !== $mobiles->unique()->count()) {
                $validator->errors()->add('members', 'The same mobile number cannot appear on more than one committee member.');
            }

            if ($config !== null) {
                if (! $config->external_member_allowed && $members->contains(fn ($m) => empty($m['user_id']))) {
                    $validator->errors()->add('members', 'External (non-user) committee members are not permitted by the current committee configuration.');
                }

                if ($members->count() < $config->min_members || $members->count() > $config->max_members) {
                    $validator->errors()->add('members', "Committee size must be between {$config->min_members} and {$config->max_members} members.");
                }
            }
        });
    }

    private function activeConfiguration(): ?CommitteeConfiguration
    {
        return CommitteeConfiguration::query()
            ->whereHas('committeeType', fn ($query) => $query->where('code', 'dpc_committee'))
            ->where('status', MasterStatus::Active->value)
            ->first();
    }
}
