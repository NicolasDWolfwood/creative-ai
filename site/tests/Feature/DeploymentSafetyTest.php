<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DeploymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_responses_are_not_indexable(): void
    {
        config()->set('creative_ai.allow_indexing', false);

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('noindex,nofollow,noarchive', escape: false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('Disallow: /', escape: false);
    }

    public function test_production_robots_uses_the_configured_canonical_url(): void
    {
        config()->set('app.url', 'https://www.example.com');
        config()->set('creative_ai.allow_indexing', true);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertSee('Disallow: /admin', escape: false)
            ->assertSee('Sitemap: https://www.example.com/sitemap.xml', escape: false);
    }

    public function test_livewire_and_php_allow_the_advertised_track_upload_size(): void
    {
        $maxTrackSize = config('creative_ai.uploads.max_track_size_kb');

        $this->assertSame(102_400, $maxTrackSize);
        $this->assertContains('max:'.$maxTrackSize, config('livewire.temporary_file_upload.rules'));

        $phpUploadConfig = parse_ini_file(base_path('docker/uploads.ini'));

        $this->assertSame('100M', $phpUploadConfig['upload_max_filesize']);
        $this->assertSame('105M', $phpUploadConfig['post_max_size']);
    }

    public function test_real_wav_detection_is_allowed_for_audio_uploads(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'creative-ai-wav-');
        $samples = str_repeat("\0", 1764);
        $wave = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE';
        $wave .= 'fmt '.pack('VvvVVvv', 16, 1, 2, 44100, 176400, 4, 16);
        $wave .= 'data'.pack('V', strlen($samples)).$samples;
        file_put_contents($path, $wave);

        try {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $accepted = config('creative_ai.uploads.track_mime_types');

            $this->assertSame('audio/x-wav', $detected);
            $this->assertContains($detected, $accepted);
            $this->assertContains('audio/wav', $accepted);
            $this->assertContains('audio/wave', $accepted);
            $this->assertContains('audio/vnd.wave', $accepted);
            $upload = new UploadedFile($path, 'sample.wav', null, null, true);
            $validator = Validator::make(['audio' => $upload], ['audio' => 'mimetypes:'.implode(',', $accepted)]);
            $this->assertTrue($validator->passes(), $validator->errors()->first('audio'));
        } finally {
            @unlink($path);
        }
    }
}
