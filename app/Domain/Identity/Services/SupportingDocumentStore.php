<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supporting documents for identity decisions and assignment orders (2026-10-27):
 * PDF only, on the private documents disk, hashed, and streamed only through a
 * controller that has checked who is asking.
 */
final class SupportingDocumentStore
{
    /**
     * @return array{document_disk: string, document_path: string, document_name: string, document_sha256: string}
     */
    public function store(UploadedFile $file, string $area): array
    {
        $disk = (string) config('dpc.documents.disk', 'local');
        $path = Storage::disk($disk)->putFileAs('private/'.$area, $file, Str::uuid()->toString().'.pdf');

        return [
            'document_disk' => $disk,
            'document_path' => (string) $path,
            'document_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'document_sha256' => (string) hash_file('sha256', (string) $file->getRealPath()),
        ];
    }

    public function download(?string $disk, ?string $path, ?string $name): StreamedResponse
    {
        abort_if($disk === null || $path === null || ! Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->download($path, $name ?? 'document.pdf');
    }
}
