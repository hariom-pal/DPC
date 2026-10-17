<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Import guidance
|--------------------------------------------------------------------------
|
| What each column of the S16-S18 upload files means, when it is required and
| what format it takes. Added 2026-09-06, because business asked the question
| and answered it with "even I do not know".
|
| Read by `ImportTemplateBuilder::columnGuide()` and used twice: written into
| every downloaded template as a `#` guidance line, and rendered on the upload
| screen as a table. One source, so the file and the screen cannot disagree.
|
| **Short sentences, plain words** (business instruction, the same day: *"make
| the suggestion language simple"*). The reader is a clerk with a spreadsheet
| open, not somebody studying a manual.
|
*/

return [
    'guide' => [
        'sn' => 'Serial number. Start at 1 and count up. Lines starting with # are help text and are ignored.',
        'status' => 'Type working or vacant. For a vacant post, fill only CAT and leave the rest blank.',
        'source' => 'How the officer sitting in this chair got here: direct, promotion or deputation. It says which quota their chair belongs to. Leave it blank on a vacant row — an empty chair belongs to no quota, and the promotion/direct split comes from the Recruitment Rule.',
        'employee_no' => 'Employee number, as your department records it. Optional.',
        'name' => 'Full name, as in the service book.',
        'mobile' => 'The officer\'s mobile number — ten digits, digits only, no +91. This is what a Promotion Candidate signs in with, and where their OTP goes, so a wrong number is an officer who cannot reach their own record. Optional, but fill it wherever you have it.',
        'dob' => 'Date of birth. Write it as DD-MM-YYYY. Example: 14-03-1974.',
        'qual' => 'Qualification. Use the exact names from the Qualification master — the allowed list is given on the help line. Separate several with a comma.',
        'promotion_to_post' => 'The post being filled by this DPC. It is the same on every row, and it is already filled in on the example line. Copy that value down.',
        'promotion_from_post' => 'The post the officer holds now. Use one of the posts named on the help line — nothing else is accepted.',
        'cat' => 'Category code: UR, SC, ST, OBC or EWS. Fill it on every row, including a vacant one.',
        'seat_category' => 'The category of the SEAT the officer sits on — the roster point they were appointed against. Not the same as CAT: an SC officer selected on merit sits on a UR seat, so CAT is SC and this is UR. Leave blank if your department does not keep a roster.',
        'date_of_joining' => 'The day the officer first joined government service. Not this post. Write it as DD-MM-YYYY.',
        'posting_on_to_post' => 'Leave blank. Fill it only if the officer is already working in the promotion post on an officiating basis. Most rows have nothing here.',
        'posting_on_from_post' => 'The day the officer took charge of the post they hold now. Required — eligibility is counted from this date. Write it as DD-MM-YYYY.',
        'roster_position' => 'The officer\'s point on the 100-point roster, 1 to 100. Leave blank if your department does not keep one.',
        'deputation_date' => 'The day the officer went on deputation. Leave blank if they are not on one. Write it as DD-MM-YYYY.',
        'deputation_to' => 'The day the deputation ends. A DATE, not a place — the column name is misleading. Leave blank if the deputation is open-ended. Write it as DD-MM-YYYY.',
        'date_of_retirement' => 'Date of retirement. Write it as DD-MM-YYYY.',
        'dop' => 'The day the officer took charge of the post they hold now. Write it as DD-MM-YYYY.',
        'tribal_years' => 'Full years served in a tribal area. A number. Write 0 if none.',
        'note' => 'Anything the committee should see about this officer. Optional.',
        'cr_year' => 'CR grade for that year: क+, क, ख, ग or घ. Leave blank if there is no CR for the year.',
        'loyalty_year' => 'Loyalty certificate given for that year? Y or N.',
        'property_year' => 'Property return filed for that year? Y or N.',
        'tribal_year' => 'Served in a tribal area that year? Y or N.',
        'seniority_no' => 'Seniority number of the officer in the final seniority list. 1 is the most senior. Each number once only. Lines starting with # are help text and are ignored.',
        'employee_id' => 'Employee ID / employee code, as your department records it. Required, and each officer once only — it is how the officer is recognised when the list is uploaded again.',
        'date_of_appointment' => 'Date of appointment to government service. Write it as DD-MM-YYYY.',
        'current_post' => 'The post the officer holds now — the post they are being considered for promotion FROM. It must be the post named on the help line.',
        'posting_on_current_post' => 'Optional. The day the officer took charge of the current post. The recruitment-rule experience check counts from this date; if you leave it blank, fill it later on the employee\'s details. Write it as DD-MM-YYYY.',
        'example_row' => 'EXAMPLE — this line is ignored, keep it or delete it',

        /*
         | Appended to a column's help text when the template is built for one DPC,
         | so the file names the values that column will accept instead of leaving
         | somebody to guess or to type a post name from memory.
         */
        'allowed' => 'Allowed: :values',
        'fixed_value' => 'Always: :value',
    ],
];
