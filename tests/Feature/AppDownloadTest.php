<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppDownloadTest extends TestCase
{
    public function test_download_serves_the_committed_apk(): void
    {
        $this->assertFileExists(public_path('mandalhishob.apk'));

        $this->get('/download')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/vnd.android.package-archive')
            ->assertDownload('MandalHishob.apk');
    }
}
