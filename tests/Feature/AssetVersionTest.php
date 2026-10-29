<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AssetVersion;
use Tests\TestCase;

/**
 * Scripts and stylesheets carry their file's version (2026-10-29), so a
 * deployment reaches browsers that cached the previous copy.
 */
class AssetVersionTest extends TestCase
{
    public function test_an_asset_url_carries_its_files_modification_time(): void
    {
        $this->assertSame(
            asset('js/portal.js').'?v='.filemtime(public_path('js/portal.js')),
            AssetVersion::url('js/portal.js'),
        );
    }

    public function test_the_pages_load_versioned_scripts_and_styles(): void
    {
        $page = (string) $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#/css/portal\.css\?v=\d+#', $page);
        $this->assertMatchesRegularExpression('#/js/portal\.js\?v=\d+#', $page);
    }
}
