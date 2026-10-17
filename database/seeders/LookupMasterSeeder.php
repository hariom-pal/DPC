<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReviewReason;
use App\Domain\Master\Models\VacancyType;
use App\Enums\MasterStatus;
use Illuminate\Database\Seeder;

class LookupMasterSeeder extends Seeder
{
    public function run(): void
    {
        $active = MasterStatus::Active->value;

        foreach ([
            ['UR', 'Unreserved', 'अनारक्षित', false],
            ['SC', 'Scheduled Caste', 'अनुसूचित जाति', true],
            ['ST', 'Scheduled Tribe', 'अनुसूचित जनजाति', true],
            ['OBC', 'Other Backward Classes', 'अन्य पिछड़ा वर्ग', true],
            ['EWS', 'Economically Weaker Section', 'आर्थिक रूप से कमजोर वर्ग', true],
        ] as [$code, $en, $hi, $reserved]) {
            ReservationCategory::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name_en' => $en, 'name_hi' => $hi, 'is_reserved' => $reserved,
                    // No category-wise reservation in promotion today: kept, inactive.
                    'status' => (bool) config('dpc.reservation.category_wise', false) ? $active : MasterStatus::Inactive->value,
                ],
            );
        }

        // PH (rule 2.1) — the only reservation in promotion today, and always active.
        ReservationCategory::query()->updateOrCreate(
            ['code' => 'PH'],
            ['name_en' => 'Persons with Disabilities (PH / PwD)', 'name_hi' => 'दिव्यांगजन (PH)', 'is_reserved' => true, 'status' => $active],
        );

        // Review reasons — PPT S35/S37.
        foreach ([
            ['name_omitted', 'Name omitted from seniority list', 'वरिष्ठता सूची में नाम न जोड़ा जाना'],
            ['wrong_order', 'Wrong seniority order', 'वरिष्ठता क्रम संबंधी त्रुटि'],
            ['wrong_roster', 'Wrong roster point', 'रोस्टर बिंदु संबंधी त्रुटि'],
            ['wrong_cr', 'Wrong CR entry', 'सी.आर. की त्रुटिपूर्ण प्रविष्टि'],
            ['reservation_rule', 'Reservation rule not followed', 'आरक्षण नियम का पालन न करना'],
            ['tribal_service', 'Tribal-area service not counted', 'ट्राइबल क्षेत्र में सेवा की गणना न करना'],
        ] as [$code, $en, $hi]) {
            ReviewReason::query()->updateOrCreate(
                ['code' => $code],
                ['name_en' => $en, 'name_hi' => $hi, 'status' => $active],
            );
        }

        // Vacancy sub-types — PPT S16/S23.
        foreach ([
            ['clear', 'Clear vacancy', 'स्पष्ट रिक्ति'],
            ['from_promotion', 'Expected from promotion', 'पदोन्नति से संभावित रिक्ति'],
            ['from_retirement', 'Expected from retirement', 'सेवानिवृत्ति से संभावित रिक्ति'],
            ['from_deputation', 'Expected from deputation', 'प्रतिनियुक्ति से संभावित रिक्ति'],
        ] as [$code, $en, $hi]) {
            VacancyType::query()->updateOrCreate(
                ['code' => $code],
                ['name_en' => $en, 'name_hi' => $hi, 'status' => $active],
            );
        }

        // DPC types — PPT S14/C3.
        foreach ([
            ['fresh', 'Fresh DPC', 'फ्रेश डी.पी.सी.'],
            ['review', 'Review DPC', 'रिव्यू डी.पी.सी.'],
            ['circulation', 'Circulation DPC', 'परिभ्रमण डी.पी.सी.'],
        ] as [$code, $en, $hi]) {
            DpcType::query()->updateOrCreate(
                ['code' => $code],
                ['name_en' => $en, 'name_hi' => $hi, 'status' => $active],
            );
        }
    }
}
