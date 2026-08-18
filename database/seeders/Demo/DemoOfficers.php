<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * The officers on each demo DPC.
 *
 * The CR grades are chosen so that the M5 engine — which the seeder never
 * second-guesses — produces a result worth showing: a Super Seat, officers on
 * the main list, officers on the waiting list, one officer stopped by the
 * disqualifying grade `घ`, one stopped by the Class-I rule on the most recent
 * CR, one below the qualifying score whom the Commission's correction later
 * rescues, and two who fall outside the zone of consideration.
 *
 * Grades are given in the order of {@see DemoChart::CR_YEARS}: 2019 … 2024. The
 * engine scores the five most recent, so 2019 sits on the chart as context and
 * outside the scoring window — which is itself worth pointing at on S18.
 */
final class DemoOfficers
{
    /**
     * School Education — District Education Officer (Class I) → Joint Director,
     * Education (Class I). Qualifying score 15; zone = 2 × vacancies + 4.
     *
     * @return list<array<string, mixed>>
     */
    public static function districtEducationOfficers(): array
    {
        return [
            ['sn' => 1, 'name' => 'Shri Rajesh Kumar Sahu', 'category' => 'UR', 'grades' => ['क', 'क+', 'क+', 'क+', 'क+', 'क+'], 'tribal' => 0],
            ['sn' => 2, 'name' => 'Smt. Sunita Verma', 'category' => 'UR', 'grades' => ['क', 'क', 'क+', 'क', 'क+', 'क'], 'tribal' => 0],
            ['sn' => 3, 'name' => 'Shri Anil Kumar Tandon', 'category' => 'SC', 'grades' => ['क', 'क+', 'क', 'क', 'क+', 'क'], 'tribal' => 0],
            ['sn' => 4, 'name' => 'Smt. Meena Baghel', 'category' => 'ST', 'grades' => ['क', 'क', 'क', 'क+', 'क', 'क+'], 'tribal' => 0],
            ['sn' => 5, 'name' => 'Shri Prakash Chandra Dewangan', 'category' => 'OBC', 'grades' => ['ख', 'क', 'क', 'क', 'क+', 'क'], 'tribal' => 0],
            ['sn' => 6, 'name' => 'Smt. Kavita Nayak', 'category' => 'UR', 'grades' => ['क', 'क', 'क', 'क', 'क', 'क'], 'tribal' => 0],
            // Below the qualifying score as submitted. The Commission corrects
            // this officer's 2024 CR at the sitting (S33 EDIT) and the
            // recalculation carries them over the line — the correction is the
            // point of the proceeding screen.
            ['sn' => 7, 'name' => 'Shri Ramesh Prasad Yadav', 'category' => 'UR', 'grades' => ['ख', 'ख', 'क', 'क', 'क', 'ख'], 'tribal' => 0,
                'note' => 'CR for 2024 disputed by the officer; department record under verification.'],
            // Scores enough, but the most recent CR is ग and a Class-I to
            // Class-I promotion does not permit that.
            ['sn' => 8, 'name' => 'Smt. Geeta Sharma', 'category' => 'UR', 'grades' => ['क', 'क+', 'क', 'क+', 'क', 'ग'], 'tribal' => 0],
            ['sn' => 9, 'name' => 'Shri Dilip Kumar Sahu', 'category' => 'SC', 'grades' => ['क', 'क', 'क', 'क+', 'क', 'क'], 'tribal' => 0],
            ['sn' => 10, 'name' => 'Smt. Anita Kujur', 'category' => 'ST', 'grades' => ['क', 'क+', 'क', 'क', 'क+', 'क'], 'tribal' => 4],
            ['sn' => 11, 'name' => 'Shri Mohan Lal Sinha', 'category' => 'OBC', 'grades' => ['ख', 'क', 'क', 'क', 'क', 'क'], 'tribal' => 0],
            // Carries the disqualifying grade घ inside the scoring window.
            ['sn' => 12, 'name' => 'Smt. Sarita Patel', 'category' => 'UR', 'grades' => ['क', 'क', 'ख', 'घ', 'क', 'क'], 'tribal' => 0],
            ['sn' => 13, 'name' => 'Shri Vinod Kumar Netam', 'category' => 'ST', 'grades' => ['क', 'क', 'क', 'क', 'क+', 'क'], 'tribal' => 6],
            ['sn' => 14, 'name' => 'Smt. Pushpa Lata Sahu', 'category' => 'OBC', 'grades' => ['क', 'क', 'क', 'क', 'क', 'क+'], 'tribal' => 0],
            // Serial 15 and 16 fall outside the zone of consideration. Both are
            // still assessed and still get a result row, which is how the
            // engine records that they were considered and not selected.
            ['sn' => 15, 'name' => 'Shri Suresh Chandra Gupta', 'category' => 'UR', 'grades' => ['क', 'क', 'क', 'क', 'क', 'क'], 'tribal' => 0],
            ['sn' => 16, 'name' => 'Smt. Rekha Bhonsle', 'category' => 'SC', 'grades' => ['क', 'क', 'क', 'क', 'क+', 'क'], 'tribal' => 0],
        ];
    }

    /**
     * Officers already serving as Joint Director, Education (S16).
     *
     * @return list<array<string, mixed>>
     */
    public static function jointDirectorsInPost(): array
    {
        return [
            ['sn' => 1, 'name' => 'Shri Om Prakash Mishra', 'category' => 'UR', 'grades' => [], 'roster' => 1],
            ['sn' => 2, 'name' => 'Smt. Shashi Kala Sahu', 'category' => 'OBC', 'grades' => [], 'roster' => 4],
            ['sn' => 3, 'name' => 'Shri Girdhari Lal Sahu', 'category' => 'SC', 'grades' => [], 'roster' => 8],
            ['sn' => 4, 'name' => 'Smt. Phoolwati Markam', 'category' => 'ST', 'grades' => [], 'roster' => 12],
            ['sn' => 5, 'name' => 'Shri Naresh Kumar Agrawal', 'category' => 'UR', 'grades' => [], 'roster' => 15],
            ['sn' => 6, 'name' => 'Shri Hemant Kumar Jain', 'category' => 'UR', 'grades' => [], 'roster' => 18],
            ['sn' => 7, 'name' => 'Smt. Urmila Ekka', 'category' => 'ST', 'grades' => [], 'roster' => 21],
        ];
    }

    /**
     * School Education — Lecturer (Class II) → Principal, Higher Secondary
     * School (Class II). Qualifying score 11, and no zone formula applies to a
     * Class-II to Class-II promotion, so every officer is considered.
     *
     * Serial 5 has no immovable-property return on record for 2022. Every
     * other officer's returns are complete, so this is the **only** hold the
     * sitting produces — and it is a property-ground hold, which is the ground
     * a Circulation DPC is permitted to resolve.
     *
     * @return list<array<string, mixed>>
     */
    public static function lecturers(): array
    {
        return [
            ['sn' => 1, 'name' => 'Shri Ashok Kumar Sahu', 'category' => 'UR', 'grades' => ['क', 'क', 'क', 'क', 'क', 'क'], 'tribal' => 0],
            ['sn' => 2, 'name' => 'Smt. Nirmala Devi Sahu', 'category' => 'UR', 'grades' => ['क', 'क+', 'क', 'क', 'क+', 'क'], 'tribal' => 0],
            ['sn' => 3, 'name' => 'Shri Bhupendra Singh Thakur', 'category' => 'OBC', 'grades' => ['ख', 'क', 'क', 'क', 'क', 'क'], 'tribal' => 0],
            ['sn' => 4, 'name' => 'Smt. Shanti Bai Markam', 'category' => 'ST', 'grades' => ['क', 'क', 'क', 'क', 'क', 'क+'], 'tribal' => 5],
            ['sn' => 5, 'name' => 'Shri Ravi Shankar Dubey', 'category' => 'UR', 'grades' => ['ख', 'ख', 'क', 'क', 'क', 'क'], 'tribal' => 0,
                'property_missing' => [2022],
                'note' => 'Immovable-property return for 2022 not received with the proposal.'],
            ['sn' => 6, 'name' => 'Smt. Lata Chandrakar', 'category' => 'SC', 'grades' => ['क', 'क', 'क', 'क', 'क', 'क'], 'tribal' => 0],
            ['sn' => 7, 'name' => 'Shri Manoj Kumar Sahu', 'category' => 'OBC', 'grades' => ['ख', 'ख', 'ख', 'क', 'क', 'ख'], 'tribal' => 0],
            ['sn' => 8, 'name' => 'Smt. Pooja Tiwari', 'category' => 'UR', 'grades' => ['ख', 'ख', 'ख', 'ख', 'क', 'ख'], 'tribal' => 0],
        ];
    }

    /**
     * Officers already serving as Principal, Higher Secondary School (S16).
     *
     * @return list<array<string, mixed>>
     */
    public static function principalsInPost(): array
    {
        return [
            ['sn' => 1, 'name' => 'Shri Jagdish Prasad Sahu', 'category' => 'UR', 'grades' => [], 'roster' => 1],
            ['sn' => 2, 'name' => 'Smt. Vandana Shukla', 'category' => 'UR', 'grades' => [], 'roster' => 3],
            ['sn' => 3, 'name' => 'Shri Dinesh Kumar Baghel', 'category' => 'ST', 'grades' => [], 'roster' => 6],
            ['sn' => 4, 'name' => 'Smt. Sarojini Minj', 'category' => 'ST', 'grades' => [], 'roster' => 9],
            ['sn' => 5, 'name' => 'Shri Alok Kumar Gupta', 'category' => 'UR', 'grades' => [], 'roster' => 11],
            ['sn' => 6, 'name' => 'Smt. Rajni Patel', 'category' => 'OBC', 'grades' => [], 'roster' => 14],
            ['sn' => 7, 'name' => 'Shri Chandra Shekhar Sahu', 'category' => 'SC', 'grades' => [], 'roster' => 17],
        ];
    }

    /**
     * A generic six-officer chart for the proposals that exist to populate a
     * workflow state rather than to be read line by line. Every officer has a
     * complete CR and property record, so these DPCs produce no holds.
     *
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    public static function generic(array $names, string $qualification = 'M.A., B.Ed.'): array
    {
        $grades = [
            ['क', 'क+', 'क', 'क+', 'क', 'क+'],
            ['क', 'क', 'क', 'क', 'क+', 'क'],
            ['क', 'क', 'क+', 'क', 'क', 'क'],
            ['ख', 'क', 'क', 'क', 'क', 'क'],
            ['क', 'क', 'क', 'क', 'क', 'क'],
            ['ख', 'ख', 'क', 'क', 'क', 'क'],
        ];

        $categories = ['UR', 'UR', 'SC', 'ST', 'OBC', 'UR'];

        $officers = [];
        foreach ($names as $index => $name) {
            $officers[] = [
                'sn' => $index + 1,
                'name' => $name,
                'category' => $categories[$index % count($categories)],
                'grades' => $grades[$index % count($grades)],
                'qualification' => $qualification,
                'tribal' => 0,
            ];
        }

        return $officers;
    }
}
