<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SignatureStatus;
use App\Models\User;
use App\Services\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignatureServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_provider_signs_and_persists_metadata_and_file(): void
    {
        Storage::fake('local');

        $signer = User::factory()->create();

        $signature = app(SignatureService::class)->sign(
            signer: $signer,
            signable: $signer,
            documentContents: 'PROCEEDINGS BODY',
            documentName: 'proceedings.txt',
        );

        $this->assertSame('mock', $signature->provider);
        $this->assertSame(SignatureStatus::Signed, $signature->status);
        $this->assertNotEmpty($signature->transaction_reference);
        $this->assertNotNull($signature->certificate_subject);
        $this->assertNotNull($signature->signed_path);

        Storage::disk('local')->assertExists($signature->signed_path);
        $this->assertStringContainsString('DIGITALLY SIGNED (MOCK)', Storage::disk('local')->get($signature->signed_path));
    }
}
