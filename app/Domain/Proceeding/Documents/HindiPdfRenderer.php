<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Documents;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

/**
 * A Blade view as a Hindi PDF (2026-11-25). dompdf does not shape Devanagari, so the
 * result documents are made with mPDF and its FreeSerif, which does.
 */
final class HindiPdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data): string
    {
        $temp = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($temp);

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'freeserif',
            'margin_left' => 18, 'margin_right' => 15, 'margin_top' => 15, 'margin_bottom' => 15,
            'tempDir' => $temp,
        ]);
        $pdf->autoScriptToLang = true;
        $pdf->autoLangToFont = true;
        $pdf->SetCreator('CGPSC e-DPC');
        $pdf->WriteHTML(view($view, $data)->render());

        return $pdf->Output('', 'S');
    }
}
