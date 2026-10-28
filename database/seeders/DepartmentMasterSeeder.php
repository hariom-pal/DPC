<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\District;
use App\Enums\DepartmentStatus;
use Illuminate\Database\Seeder;

/**
 * The department master: the departments of the Government of Chhattisgarh
 * (2026-10-26), shown in the department dropdowns. The Administrator adds, renames
 * or retires departments on the Department screen.
 *
 * Codes form the prefix of the DPC ID (e.g. "SE" -> SE201711111), so they are short,
 * capital letters only, and never reused.
 *
 * Every department is created unregistered and without an account; it registers
 * by invitation, which needs the district, place, PIN and e-mail filled in
 * (`Department::hasRegistrationDetails()`). The secretariat departments all sit in
 * Mantralaya, so the address is common to them.
 *
 * **The e-mail is a placeholder** (`<code>.dpc@cg.gov.in`, the user's choice of
 * 2026-10-26), not a verified official address. It is a contact detail shown on
 * the registration screen; the invitation itself goes to the address the
 * Administrator types. Replace it with the department's official address on the
 * Department screen.
 *
 * Idempotent and never overwriting: a new department is created in full; an
 * existing one (same code) only has its *blank* address fields filled; a
 * department whose name the Administrator has given another code is left alone.
 */
class DepartmentMasterSeeder extends Seeder
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private const DEPARTMENTS = [
        ['AGR', 'Agriculture Development and Farmer Welfare and Bio-Technology Department'],
        ['AHD', 'Animal Husbandry Development Department'],
        ['AVN', 'Aviation Department'],
        ['BCMD', 'Backward Classes and Minority Development Department'],
        ['CI', 'Commerce and Industry Department'],
        ['CT', 'Commercial Tax Department'],
        ['CTEX', 'Commercial Tax (Excise) Department'],
        ['CTREG', 'Commercial Tax (Registration) Department'],
        ['COOP', 'Co-operation Department'],
        ['CUL', 'Culture Department'],
        ['EIT', 'Electronics and Information Technology Department'],
        ['ENGY', 'Energy Department'],
        ['FIN', 'Finance Department'],
        ['FISH', 'Fisheries Department'],
        ['FCS', 'Food, Civil Supplies and Consumer Protection Department'],
        ['FOR', 'Forest and Climate Change Department'],
        ['GAD', 'General Administration Department'],
        ['GGC', 'Good Governance and Convergence Department'],
        ['HE', 'Higher Education Department'],
        ['HOME', 'Home Department'],
        ['HENV', 'Housing and Environment Department'],
        ['JAIL', 'Jail Department'],
        ['LAB', 'Labour Department'],
        ['LAW', 'Law and Legislative Affairs Department'],
        ['MED', 'Medical Education Department'],
        ['MIN', 'Mineral Resources Department'],
        ['PRD', 'Panchayat and Rural Development Department'],
        ['PAR', 'Parliamentary Affairs Department'],
        ['PES', 'Planning, Economics and Statistics Department'],
        ['PGR', 'Public Grievance Redressal Department'],
        ['PHFW', 'Public Health and Family Welfare Department'],
        ['PHE', 'Public Health Engineering Department'],
        ['PR', 'Public Relations Department'],
        ['PWD', 'Public Works Department'],
        ['RTE', 'Religious Trust and Endowment Department'],
        ['REV', 'Revenue and Disaster Management Department'],
        ['STSC', 'Scheduled Tribes and Scheduled Castes Development Department'],
        ['SE', 'School Education Department'],
        ['SDTE', 'Skill Development, Technical Education and Employment Department'],
        ['SW', 'Social Welfare Department'],
        ['SYW', 'Sports and Youth Welfare Department'],
        ['TOUR', 'Tourism Department'],
        ['TRN', 'Transport Department'],
        ['TPP', 'Twenty Point Programme Implementation Department'],
        ['UAD', 'Urban Administration and Development Department'],
        ['VI', 'Village Industries Department'],
        ['WR', 'Water Resources Department'],
        ['WCD', 'Women and Child Development Department'],
    ];

    private const PLACE = 'Mahanadi Bhawan, Mantralaya, Nava Raipur Atal Nagar';

    private const PIN = '492002';

    private const DISTRICT = 'Raipur';

    public function run(): void
    {
        $districtId = District::query()->where('name_en', self::DISTRICT)->value('id');

        foreach (self::DEPARTMENTS as [$code, $name]) {
            $details = [
                'district_id' => $districtId,
                'place' => self::PLACE,
                'pin' => self::PIN,
                'email' => strtolower($code).'.dpc@cg.gov.in',
            ];

            $department = Department::withTrashed()->where('code', $code)->first();

            if ($department instanceof Department) {
                // Fill only what is blank: the Administrator's entries stand.
                $blank = array_filter($details, fn (string $field): bool => blank($department->{$field}), ARRAY_FILTER_USE_KEY);
                if ($blank !== []) {
                    $department->forceFill($blank)->save();
                }

                continue;
            }

            if (Department::withTrashed()->where('name', $name)->exists()) {
                continue;
            }

            Department::query()->create(['code' => $code, 'name' => $name, 'status' => DepartmentStatus::Unregistered->value] + $details);
        }
    }
}
