<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class AudioDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_audio_get_and_head_advertise_byte_ranges_and_keep_the_revalidation_policy(): void
    {
        Storage::fake('local');
        $bytes = '0123456789abcdef';
        $track = $this->standaloneTrack('full-audio', $bytes);
        $url = route('media.tracks.audio', $track);

        $get = $this->get($url)
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Length', (string) strlen($bytes))
            ->assertHeader('Content-Disposition', 'inline')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');

        $this->assertSame($bytes, $this->emittedContent($get));

        $head = $this->head($url)
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Length', (string) strlen($bytes))
            ->assertHeader('Content-Disposition', 'inline')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');

        $this->assertSame('', $this->emittedContent($head));
    }

    public function test_public_audio_range_returns_the_requested_bytes_and_exact_partial_headers(): void
    {
        Storage::fake('local');
        $track = $this->standaloneTrack('partial-audio', '0123456789abcdef');

        $response = $this->withHeader('Range', 'bytes=2-5')
            ->get(route('media.tracks.audio', $track))
            ->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Range', 'bytes 2-5/16')
            ->assertHeader('Content-Length', '4')
            ->assertHeader('Content-Disposition', 'inline')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');

        $this->assertSame('2345', $this->emittedContent($response));
    }

    public function test_unsatisfiable_audio_range_returns_416_without_file_bytes(): void
    {
        Storage::fake('local');
        $track = $this->standaloneTrack('invalid-range-audio', '0123456789abcdef');

        $response = $this->withHeader('Range', 'bytes=99-120')
            ->get(route('media.tracks.audio', $track))
            ->assertStatus(416)
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Range', 'bytes */16')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');

        $this->assertSame('', $this->emittedContent($response));
    }

    public function test_range_request_cannot_bypass_unpublished_audio_access(): void
    {
        Storage::fake('local');
        $path = 'tracks/audio/private-range.mp3';
        Storage::disk('local')->put($path, 'private audio bytes');
        $track = Track::withoutEvents(fn (): Track => Track::query()->create([
            'title' => 'Private Range',
            'slug' => 'private-range',
            'audio_path' => $path,
            'standalone_published' => false,
        ]));

        $this->withHeader('Range', 'bytes=0-3')
            ->get(route('media.tracks.audio', $track))
            ->assertNotFound();
    }

    public function test_album_inherited_audio_supports_ranges_and_is_denied_again_after_unpublishing(): void
    {
        Storage::fake('local');
        $bytes = 'album-track-bytes';
        $path = 'tracks/audio/album-range.mp3';
        Storage::disk('local')->put($path, $bytes);
        $album = Album::withoutEvents(fn (): Album => Album::query()->create([
            'title' => 'Range Album',
            'slug' => 'range-album',
            'published' => true,
            'published_at' => now(),
        ]));
        $track = Track::withoutEvents(fn (): Track => Track::query()->create([
            'album_id' => $album->getKey(),
            'title' => 'Inherited Range',
            'slug' => 'inherited-range',
            'audio_path' => $path,
            'standalone_published' => false,
        ]));
        $url = route('media.tracks.audio', $track);

        $response = $this->withHeader('Range', 'bytes=6-10')
            ->get($url)
            ->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Range', 'bytes 6-10/17')
            ->assertHeader('Content-Length', '5')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');

        $this->assertSame('track', $this->emittedContent($response));

        $album->forceFill(['published' => false])->saveQuietly();

        $this->withHeader('Range', 'bytes=0-3')
            ->get($url)
            ->assertNotFound();
    }

    private function standaloneTrack(string $slug, string $bytes): Track
    {
        $path = 'tracks/audio/'.$slug.'.mp3';
        Storage::disk('local')->put($path, $bytes);

        return Track::withoutEvents(fn (): Track => Track::query()->create([
            'title' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'audio_path' => $path,
            'standalone_published' => true,
            'standalone_published_at' => now(),
        ]));
    }

    private function emittedContent(TestResponse $response): string
    {
        $binaryResponse = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $binaryResponse);

        ob_start();

        try {
            $binaryResponse->sendContent();
            $content = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertIsString($content);

        return $content;
    }
}
