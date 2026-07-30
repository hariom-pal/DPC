<?php

declare(strict_types=1);

namespace App\Integrations\Mock;

use App\Integrations\Contracts\DirectoryProvider;

/**
 * Static Chhattisgarh district list (LGD state code 22). District LGD codes
 * are intentionally left null here and are populated by the live LGD provider
 * later (see milestone questions) — names are authoritative, numeric LGD codes
 * are reconciled on live sync rather than hand-entered.
 */
class SeedDirectoryProvider implements DirectoryProvider
{
    private const STATE_LGD = '22'; // Chhattisgarh

    public function districts(): array
    {
        $names = [
            ['Balod', 'बालोद'],
            ['Baloda Bazar', 'बलौदाबाजार'],
            ['Balrampur', 'बलरामपुर'],
            ['Bastar', 'बस्तर'],
            ['Bemetara', 'बेमेतरा'],
            ['Bijapur', 'बीजापुर'],
            ['Bilaspur', 'बिलासपुर'],
            ['Dantewada', 'दंतेवाड़ा'],
            ['Dhamtari', 'धमतरी'],
            ['Durg', 'दुर्ग'],
            ['Gariaband', 'गरियाबंद'],
            ['Gaurela-Pendra-Marwahi', 'गौरेला-पेंड्रा-मरवाही'],
            ['Janjgir-Champa', 'जांजगीर-चांपा'],
            ['Jashpur', 'जशपुर'],
            ['Kabirdham', 'कबीरधाम'],
            ['Kanker', 'कांकेर'],
            ['Kondagaon', 'कोंडागांव'],
            ['Korba', 'कोरबा'],
            ['Koriya', 'कोरिया'],
            ['Mahasamund', 'महासमुंद'],
            ['Mungeli', 'मुंगेली'],
            ['Narayanpur', 'नारायणपुर'],
            ['Raigarh', 'रायगढ़'],
            ['Raipur', 'रायपुर'],
            ['Rajnandgaon', 'राजनांदगांव'],
            ['Sukma', 'सुकमा'],
            ['Surajpur', 'सूरजपुर'],
            ['Surguja', 'सरगुजा'],
        ];

        return array_map(
            static fn (array $n): array => [
                'lgd_code' => null,
                'state_lgd_code' => self::STATE_LGD,
                'name_en' => $n[0],
                'name_hi' => $n[1],
            ],
            $names,
        );
    }
}
