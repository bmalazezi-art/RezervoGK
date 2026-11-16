<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_have_link_preview_tags_and_app_icons(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<meta property="og:type" content="website">', false)
            ->assertSee('<meta property="og:image" content="'.url('/brand/og-image.png').'">', false)
            ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false)
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false);
    }

    public function test_brand_files_exist(): void
    {
        foreach ([
            'favicon.ico', 'apple-touch-icon.png', 'manifest.webmanifest',
            'brand/og-image.png', 'brand/icon-192.png', 'brand/icon-512.png',
            'brand/gjej-portier-logo-on-dark.svg', 'brand/gjej-portier-logo-on-light.svg',
            'brand/gjej-portier-app-icon.svg',
        ] as $file) {
            $this->assertGreaterThan(0, filesize(public_path($file)), $file);
        }
    }
}
