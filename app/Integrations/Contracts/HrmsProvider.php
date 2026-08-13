<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * Abstraction over the Human Resource Management System (architect ruling,
 * 2026-08-13: never hardcode an external integration; only Providers may
 * talk to external systems).
 *
 * CGPSC has not supplied an HRMS API. The contract exists now so that when
 * one arrives, only the provider implementation, its configuration, and its
 * credentials change — no business logic, controller, or service.
 *
 * Deliberately narrow. It exposes availability and a single lookup returning
 * the standard ten-field employee snapshot the proposal tables already use,
 * because that is the only shape M4 has any use for. A speculative wider
 * contract would be guesswork about an API nobody has seen, and M4 is
 * architecture-frozen: no current workflow calls this, and none may be
 * changed to call it without a future milestone.
 */
interface HrmsProvider
{
    public function name(): string;

    /**
     * Whether officer data can be fetched right now. False means every
     * caller must fall back to manual entry or Excel import — which is the
     * only mode the system has ever run in, so nothing degrades.
     */
    public function isAvailable(): bool;

    /**
     * Fetch one employee's snapshot by service/employee number.
     *
     * Returns null when the employee is unknown. Implementations must return
     * the standard ten-field snapshot shape used by the proposal tables:
     * employee_number, name, dob, qualification, current_post, feeder_post,
     * reservation_category_code, date_of_joining_service,
     * date_of_posting_on_current_post, date_of_posting_on_feeder_post.
     *
     * Note the *code* for reservation category, not an id: an external system
     * has no knowledge of this application's primary keys, exactly as the
     * Excel importer already assumes.
     *
     * @return array<string, mixed>|null
     */
    public function findEmployee(string $employeeNumber): ?array;
}
