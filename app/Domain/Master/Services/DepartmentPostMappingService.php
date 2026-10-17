<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DepartmentPostOrder;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Enums\MasterStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The only way a Department Post Mapping is superseded, and the only way a
 * Government Order is recorded against one.
 *
 * **Why superseding is a separate act from editing.** A mapping is what a
 * Government Order says. Correcting a typo in a post's name is an edit; the
 * Government issuing a new order is not — it is a new mapping, effective from a
 * date, with the previous one closed rather than changed. Editing in place would
 * make a DPC conducted last year appear to have run under terms that did not exist
 * at the time, which is exactly the thing a legal reference is supposed to prevent.
 *
 * So {@see supersede()} writes two rows in one transaction: the incumbent gains an
 * `effective_to` and loses its flag, and a new row takes its place. Every proposal
 * that referenced the old mapping still references it, and still resolves to the
 * order that authorised it.
 *
 * **The incumbent is read under a write lock**, the same doctrine as TD-5 and
 * `DepartmentAssignmentService`: the unique index makes two active mappings
 * impossible, so without the lock a concurrent supersession would not corrupt
 * anything — it would fail halfway, with a constraint violation the Administrator
 * cannot act on. The lock turns that into a wait.
 */
class DepartmentPostMappingService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * Close the mapping in force and open its replacement.
     *
     * @param  array<string, mixed>  $attributes  the new mapping's own terms
     */
    public function supersede(
        DepartmentPost $incumbent,
        array $attributes,
        User $actor,
        ?UploadedFile $document = null,
    ): DepartmentPost {
        return DB::transaction(function () use ($incumbent, $attributes, $actor, $document): DepartmentPost {
            $current = DepartmentPost::query()
                ->whereKey($incumbent->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $current->isInForce()) {
                throw new RuntimeException(
                    'That mapping has already been superseded. Supersede the one currently in force, '
                    .'so the chain of Government Orders stays in order.'
                );
            }

            $from = Carbon::parse((string) $attributes['effective_from'])->startOfDay();

            if ($current->effective_from !== null && $from->lte($current->effective_from)) {
                throw new RuntimeException(sprintf(
                    'The new order takes effect on %s, which is not after the mapping it replaces (%s). '
                    .'Two mappings cannot both be in force on the same day.',
                    $from->format('d-m-Y'),
                    $current->effective_from->format('d-m-Y'),
                ));
            }

            // The day before the successor begins, so no day is covered twice.
            $current->forceFill([
                'effective_to' => $from->copy()->subDay(),
                'active_flag' => null,
                'status' => MasterStatus::Inactive->value,
            ])->save();

            $replacement = new DepartmentPost([
                'department_id' => $current->department_id,

                // The post itself does not change — a new order re-authorises the
                // same post on new terms. Taking the name from the incumbent rather
                // than from the request is what keeps the two rows a chain rather
                // than two unrelated mappings.
                'name' => $current->name,

                'class_level' => $attributes['class_level'],
                'pay_scale_id' => $attributes['pay_scale_id'] ?? null,
                'promotion_duration_years' => $attributes['promotion_duration_years'] ?? null,

                /*
                 | Carried onto the replacement (correction of 2026-09-06).
                 |
                 | Falls back to the incumbent's figure rather than to null: a
                 | caller that does not mention the strength means "unchanged", and
                 | the alternative — silently dropping it — is what made
                 | superseding a mapping break the DPCs that depended on it.
                 */

                'effective_from' => $from,
            ]);

            $replacement->status = MasterStatus::Active;
            $replacement->active_flag = 1;
            $replacement->save();

            /*
             | The superseding order itself, recorded against the new mapping
             | (change register of 2026-08-26, item 10).
             |
             | A supersession is *caused by* an order, so the new mapping always
             | gets one — the request makes the number and the date mandatory. The
             | PDF may follow later, which is why `recordOrder()` takes a nullable
             | file rather than requiring one.
             */
            $order = $this->recordOrder(
                $replacement,
                (string) $attributes['government_order_number'],
                Carbon::parse((string) $attributes['government_order_date']),
                $document,
                $actor,
            );

            $this->timeline->record(
                'department_post.superseded',
                $replacement,
                sprintf(
                    '%s in %s superseded with effect from %s under %s. The previous mapping is kept and closed on %s.',
                    $current->name,
                    $current->department->name,
                    $from->format('d-m-Y'),
                    $order->citation(),
                    $from->copy()->subDay()->format('d-m-Y'),
                ),
            );

            return $replacement;
        });
    }

    /**
     * Record a Government Order against a mapping (change register of 2026-08-26,
     * item 10).
     *
     * **Adds, never replaces.** This used to be `attachDocument()`, which retired
     * the mapping's current PDF so that one file was "current" — the shape the
     * register calls insufficient. A mapping may be governed by several orders at
     * once, so each one is simply another row and none of them retires another.
     *
     * The PDF is optional: a department may know an order's number and date before
     * the signed copy reaches them, and refusing the citation until the file
     * arrives would mean the portal held less than the department did.
     *
     * **The hash is what makes the file evidence**, and it is taken from the bytes
     * that were received rather than re-read from disk afterwards — a hash computed
     * from the stored copy would certify the copy against itself.
     */
    public function recordOrder(
        DepartmentPost $post,
        string $number,
        Carbon $date,
        ?UploadedFile $file,
        User $actor,
    ): DepartmentPostOrder {
        return DB::transaction(function () use ($post, $number, $date, $file, $actor): DepartmentPostOrder {
            $attributes = [
                'department_post_id' => $post->id,
                'government_order_number' => trim($number),
                'government_order_date' => $date->toDateString(),

                // Appended, so "Add More" puts the new order after the existing
                // ones when two share a date.
                'sort_order' => (int) DepartmentPostOrder::query()
                    ->where('department_post_id', $post->id)
                    ->max('sort_order') + 1,

                'uploaded_by' => $actor->id,
            ];

            if ($file !== null) {
                $disk = (string) config('dpc.documents.disk', 'local');
                $contents = (string) file_get_contents($file->getRealPath());

                $path = Storage::disk($disk)->putFile(
                    sprintf('private/department-posts/%d', $post->id),
                    $file,
                );

                if ($path === false) {
                    throw new RuntimeException('The Government Order could not be stored.');
                }

                $attributes += [
                    'disk' => $disk,
                    'path' => $path,

                    // Sanitised, and only ever displayed. A filename from a browser
                    // is user input, and it is never used to build the stored path.
                    'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),

                    'size_bytes' => $file->getSize() ?: 0,
                    'sha256' => hash('sha256', $contents),
                ];
            }

            $order = DepartmentPostOrder::query()->create($attributes);

            $this->timeline->record(
                'department_post.order_recorded',
                $post,
                sprintf('Government Order %s recorded against %s', $order->citation(), $post->name),
                ['order_uuid' => $order->uuid, 'has_document' => $order->hasDocument()],
                $actor,
            );

            return $order;
        });
    }
}
