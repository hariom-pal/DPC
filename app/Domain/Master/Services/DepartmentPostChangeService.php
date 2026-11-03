<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DepartmentPostChange;
use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;

/**
 * The Authorized Officer's Post Mapping changes, held for the Department Admin
 * (UAT, 2026-11-03): "AO k paas post mapping rehne do but wo fir D/A k paas approval
 * k liye jaaye; direct without approval sirf DA kar sakta hai".
 *
 * **A request changes nothing.** The form's values and its PDFs are kept on a
 * `department_post_changes` row; `department_posts` is untouched until the
 * Department Admin approves. The approval then makes the change through
 * `MasterCrudService` and `DepartmentPostMappingService`, the code the Department
 * Admin's own save runs, so an approved change and a direct one are the same change.
 *
 * **One open request per mapping.** A second edit of a mapping already awaiting
 * approval would leave the Department Admin deciding between two versions of it.
 */
final class DepartmentPostChangeService
{
    public function __construct(
        private readonly MasterCrudService $crud,
        private readonly DepartmentPostMappingService $mappings,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the validated form, files excluded
     * @param  array<string, UploadedFile|null>  $files  by form field (`orders.0.document`, `government_order_document`)
     */
    public function propose(
        string $action,
        int $departmentId,
        ?DepartmentPost $post,
        array $payload,
        array $files,
        User $actor,
    ): DepartmentPostChange {
        $this->assertNoneOpen($departmentId, $post, (string) ($payload['name'] ?? ''));

        $change = new DepartmentPostChange([
            'department_id' => $departmentId,
            'department_post_id' => $post?->id,
            'action' => $action,
            'payload' => $this->plain($payload),
            'status' => DepartmentPostChange::STATUS_PENDING,
            'requested_by' => $actor->id,
        ]);
        $change->save();

        $change->forceFill(['documents' => $this->hold($change, $files)])->save();

        $this->timeline->record(
            'department_post.change_requested',
            $post ?? $change,
            sprintf('%s of %s sent to the Department Admin for approval', ucfirst($action), $post->name ?? (string) ($payload['name'] ?? '')),
            ['change_uuid' => $change->uuid],
            $actor,
        );

        return $change;
    }

    /**
     * Apply the change as the Department Admin. Refuses (RuntimeException) when the
     * mapping moved on since the request: superseded, deleted, or no longer in force.
     */
    public function approve(DepartmentPostChange $change, User $actor): ?DepartmentPost
    {
        return DB::transaction(function () use ($change, $actor): ?DepartmentPost {
            /** @var DepartmentPostChange $locked */
            $locked = DepartmentPostChange::query()->whereKey($change->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new RuntimeException((string) __('admin.department_posts.changes.already_decided'));
            }

            $requester = User::query()->find($locked->requested_by) ?? $actor;
            $result = $this->apply($locked, $requester);

            $locked->forceFill([
                'status' => DepartmentPostChange::STATUS_APPROVED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'result_post_id' => $result?->id,
            ])->save();

            $this->timeline->record(
                'department_post.change_approved',
                $result ?? $locked,
                sprintf('%s requested by %s approved by the Department Admin', ucfirst($locked->action), $requester->name),
                ['change_uuid' => $locked->uuid],
                $actor,
            );

            return $result;
        });
    }

    public function reject(DepartmentPostChange $change, User $actor, string $remarks): void
    {
        DB::transaction(function () use ($change, $actor, $remarks): void {
            /** @var DepartmentPostChange $locked */
            $locked = DepartmentPostChange::query()->whereKey($change->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new RuntimeException((string) __('admin.department_posts.changes.already_decided'));
            }

            $locked->forceFill([
                'status' => DepartmentPostChange::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'remarks' => $remarks,
            ])->save();

            $this->timeline->record(
                'department_post.change_rejected',
                $locked->post ?? $locked,
                sprintf('%s returned by the Department Admin: %s', ucfirst($locked->action), $remarks),
                ['change_uuid' => $locked->uuid],
                $actor,
            );
        });
    }

    private function apply(DepartmentPostChange $change, User $requester): ?DepartmentPost
    {
        $payload = $change->payload;
        $post = $change->department_post_id === null
            ? null
            : DepartmentPost::query()->whereKey($change->department_post_id)->lockForUpdate()->first();

        if ($change->action !== DepartmentPostChange::ACTION_CREATE && $post === null) {
            throw new RuntimeException((string) __('admin.department_posts.changes.post_gone'));
        }

        switch ($change->action) {
            case DepartmentPostChange::ACTION_CREATE:
                $created = $this->crud->create(new DepartmentPost, $this->terms($payload));
                $this->recordOrders($change, $created, $requester);

                return $created;

            case DepartmentPostChange::ACTION_UPDATE:
                /** @var DepartmentPost $post */
                if ($post->isSuperseded()) {
                    throw new RuntimeException((string) __('admin.department_posts.changes.post_superseded'));
                }
                $this->crud->update($post, $this->terms($payload));
                $this->recordOrders($change, $post, $requester);

                return $post;

            case DepartmentPostChange::ACTION_SUPERSEDE:
                /** @var DepartmentPost $post */
                return $this->withDocument($change, 'government_order_document', fn (?File $file, ?string $name): DepartmentPost => $this->mappings->supersede(
                    $post,
                    $payload,
                    $requester,
                    $file,
                    $name,
                ));

            case DepartmentPostChange::ACTION_DELETE:
                /** @var DepartmentPost $post */
                $this->crud->delete($post);

                return null;
        }

        throw new RuntimeException('Unknown Post Mapping change: '.$change->action);
    }

    /**
     * The mapping's own columns: the Government Orders are rows of their own.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function terms(array $payload): array
    {
        unset($payload['orders'], $payload['government_order_document']);

        return $payload;
    }

    /**
     * As `DepartmentPostRequest::orders()`: rows with both a number and a date.
     */
    private function recordOrders(DepartmentPostChange $change, DepartmentPost $post, User $requester): void
    {
        /** @var array<int|string, array<string, mixed>> $rows */
        $rows = (array) ($change->payload['orders'] ?? []);

        foreach ($rows as $index => $row) {
            $number = trim((string) ($row['government_order_number'] ?? ''));
            $date = $row['government_order_date'] ?? null;

            if ($number === '' || $date === null || $date === '') {
                continue;
            }

            $this->withDocument($change, "orders.{$index}.document", fn (?File $file, ?string $name) => $this->mappings->recordOrder(
                $post,
                $number,
                Carbon::parse((string) $date),
                $file,
                $requester,
                $name,
            ));
        }
    }

    /**
     * Run `$use` with the held PDF for `$field` as a local file (any disk), or null.
     *
     * @template T
     *
     * @param  callable(?File, ?string): T  $use
     * @return T
     */
    private function withDocument(DepartmentPostChange $change, string $field, callable $use): mixed
    {
        $held = collect($change->documents ?? [])->firstWhere('field', $field);

        if ($held === null) {
            return $use(null, null);
        }

        $temporary = (string) tempnam(sys_get_temp_dir(), 'dpc-order-');
        $pdf = $temporary.'.pdf';
        rename($temporary, $pdf);

        try {
            file_put_contents($pdf, (string) Storage::disk($held['disk'])->get($held['path']));

            return $use(new File($pdf), $held['original_name']);
        } finally {
            @unlink($pdf);
        }
    }

    /**
     * Store the request's PDFs now, so the approval needs nothing from the browser.
     *
     * @param  array<string, UploadedFile|null>  $files
     * @return list<array{field: string, disk: string, path: string, original_name: string, size_bytes: int, sha256: string}>
     */
    private function hold(DepartmentPostChange $change, array $files): array
    {
        $disk = (string) config('dpc.documents.disk', 'local');
        $directory = 'private/department-post-changes/'.$change->uuid;
        $held = [];

        foreach ($files as $field => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = Storage::disk($disk)->putFile($directory, $file);

            if ($path === false) {
                throw UnableToWriteFile::atLocation($directory, 'The Government Order could not be stored.');
            }

            $held[] = [
                'field' => (string) $field,
                'disk' => $disk,
                'path' => $path,
                'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                'size_bytes' => (int) ($file->getSize() ?: 0),
                'sha256' => hash('sha256', (string) file_get_contents($file->getRealPath())),
            ];
        }

        return $held;
    }

    private function assertNoneOpen(int $departmentId, ?DepartmentPost $post, string $name): void
    {
        $open = DepartmentPostChange::query()
            ->pending()
            ->where('department_id', $departmentId)
            ->when(
                $post !== null,
                fn ($q) => $q->where('department_post_id', $post?->id),
                fn ($q) => $q->where('action', DepartmentPostChange::ACTION_CREATE)->where('payload->name', $name),
            )
            ->exists();

        if ($open) {
            throw new RuntimeException((string) __('admin.department_posts.changes.already_open'));
        }
    }

    /**
     * Dates as `Y-m-d` strings, so the JSON reads back exactly as the form sent it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function plain(array $payload): array
    {
        array_walk_recursive($payload, function (mixed &$value): void {
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
        });

        return $payload;
    }
}
