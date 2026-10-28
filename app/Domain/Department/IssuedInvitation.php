<?php

declare(strict_types=1);

namespace App\Domain\Department;

use App\Domain\Department\Models\DepartmentInvitation;
use SensitiveParameter;

/**
 * An invitation just issued or re-issued, with its registration link (2026-10-26).
 *
 * The link carries the token, which is stored only as a hash, so this is the one
 * moment it exists in clear: the Portal Super Admin is shown it once, beside the
 * e-mail, so a department can be invited while no mail server delivers. It is never
 * logged, flashed, audited or written to the timeline.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public DepartmentInvitation $invitation,
        #[SensitiveParameter] public string $link,
        public bool $mailed,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['invitation' => $this->invitation->id, 'link' => '[redacted]', 'mailed' => $this->mailed];
    }
}
