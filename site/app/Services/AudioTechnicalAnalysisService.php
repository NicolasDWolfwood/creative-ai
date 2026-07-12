<?php

namespace App\Services;

use App\Models\Track;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class AudioTechnicalAnalysisService
{
    public function analyze(Track $track): void
    {
        $track->forceFill(['analysis_status' => 'processing', 'analysis_error' => null])->saveQuietly();
        try {
            $path = Storage::disk('public')->path($track->audio_path);
            if (! is_file($path)) {
                throw new \RuntimeException('Audio file is missing from storage.');
            }
            $probe = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration,bit_rate:stream=codec_type,codec_name,sample_rate,channels', '-of', 'json', $path]);
            $probe->mustRun();
            $data = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $audio = collect($data['streams'] ?? [])->firstWhere('codec_type', 'audio') ?? [];
            $decode = new Process(['ffmpeg', '-v', 'error', '-i', $path, '-ac', '1', '-ar', '2000', '-f', 's16le', '-']);
            $decode->setTimeout(180)->mustRun();
            $hash = hash_file('sha256', $path);
            $issues = $this->issues($track, $audio, $hash);
            $track->forceFill([
                'analysis_status' => 'ready', 'analyzed_at' => now(), 'audio_hash' => $hash,
                'audio_codec' => $audio['codec_name'] ?? null, 'bitrate' => (int) ($data['format']['bit_rate'] ?? 0) ?: null,
                'sample_rate' => (int) ($audio['sample_rate'] ?? 0) ?: null, 'channels' => (int) ($audio['channels'] ?? 0) ?: null,
                'duration_seconds' => $track->duration_seconds ?: (int) round((float) ($data['format']['duration'] ?? 0)),
                'waveform' => $this->waveform($decode->getOutput()),
                'health_status' => $issues === [] ? 'healthy' : 'attention', 'health_issues' => $issues,
            ])->saveQuietly();
        } catch (\Throwable $e) {
            $track->forceFill(['analysis_status' => 'failed', 'analysis_error' => mb_substr($e->getMessage(), 0, 1000), 'analyzed_at' => now(), 'health_status' => 'attention', 'health_issues' => ['Technical analysis failed']])->saveQuietly();
        }
    }

    /** @return array<int, int> */
    public function waveform(string $pcm, int $points = 120): array
    {
        $samples = array_values(unpack('s*', $pcm) ?: []);
        if ($samples === []) {
            return [];
        }
        $size = max(1, (int) ceil(count($samples) / $points));

        return collect(array_chunk($samples, $size))->take($points)->map(fn (array $chunk): int => (int) round(max(array_map('abs', $chunk)) / 32767 * 100))->all();
    }

    /** @return array<int, string> */
    protected function issues(Track $track, array $audio, string $hash): array
    {
        $issues = [];
        if (blank($track->artist)) {
            $issues[] = 'Artist is missing';
        }
        if (! $track->cover_url) {
            $issues[] = 'Cover artwork is missing';
        }
        if (! in_array($audio['codec_name'] ?? null, ['mp3', 'aac', 'vorbis', 'opus', 'flac', 'pcm_s16le', 'pcm_s24le'], true)) {
            $issues[] = 'Uncommon or unsupported codec';
        }
        if (Track::query()->where('audio_hash', $hash)->whereKeyNot($track->getKey())->exists()) {
            $issues[] = 'Possible duplicate audio';
        }

        return $issues;
    }
}
