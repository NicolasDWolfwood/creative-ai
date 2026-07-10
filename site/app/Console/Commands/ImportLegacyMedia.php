<?php

namespace App\Console\Commands;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\SiteSetting;
use App\Models\Track;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportLegacyMedia extends Command
{
    protected $signature = 'creative-ai:import-legacy
        {--path= : Legacy root path containing images/ and music/}
        {--force : Re-copy files and update existing records}';

    protected $description = 'Import the existing static Creative-Ai image and music library.';

    /**
     * @var array<string, array{title:string, artist:string}>
     */
    protected array $trackLabels = [
        '00-LeagueOfArcane-RiseFromTheShadows_Alt.Ending.mp3' => ['title' => 'From The Shadows', 'artist' => 'League Of Arcane'],
        '01-EternalGuardian.mp3' => ['title' => 'Eternal Guardian', 'artist' => 'Kelly Fakes'],
        '02-EmeraldFlutter.mp3' => ['title' => 'Emerald Flutter', 'artist' => 'Florence Noname'],
        '03-FarmingOutsideForAMan.mp3' => ['title' => 'Farming Outside', 'artist' => '5ive Kids Back'],
        '04-BennyHardBenjiAnthem.mp3' => ['title' => "Benji's Anthem", 'artist' => 'Benny Hard'],
        '05-themusicalforthealliance.mp3' => ['title' => 'For The Alliance', 'artist' => 'The Musical'],
        '06-sidsEnchantedMoor.mp3' => ['title' => 'Enchanted Moor', 'artist' => 'Sidical'],
        '07-theatershowMoistyRepose.mp3' => ['title' => "Moisty's Repose", 'artist' => 'Theater Show'],
        '08-HooverPhony-SpectrumDreams.mp3' => ['title' => 'Spectrum Dreams', 'artist' => 'Hooverphone'],
        '09-AngelicKCelestialSeraphim.mp3' => ['title' => 'Celestial Seraphim', 'artist' => 'Angelic-K'],
        '10-MoistyOsborne-KingoftheWaves.mp3' => ['title' => 'King of the Waves', 'artist' => 'Moisty Osborne'],
    ];

    public function handle(): int
    {
        $legacyRoot = $this->resolveLegacyRoot();

        if (! $legacyRoot) {
            $this->error('Could not find legacy media. Pass --path or set CREATIVE_AI_LEGACY_PATH.');

            return self::FAILURE;
        }

        $collection = Collection::query()->firstOrCreate(
            ['slug' => 'generative-art'],
            [
                'title' => 'Generative Art',
                'description' => 'A living gallery of generative art by John Reijmer.',
                'featured' => true,
                'published' => true,
                'published_at' => now(),
            ],
        );

        $artworkCount = $this->importArtworks($legacyRoot, $collection);
        $trackCount = $this->importTracks($legacyRoot);

        SiteSetting::query()->updateOrCreate(
            ['key' => 'home_intro'],
            ['value' => [
                'title' => 'Creative Thoughts',
                'body' => 'A site made out of love for creating art with ComfyUI and Automatic1111.',
            ]],
        );

        $this->info("Imported or updated {$artworkCount} artworks and {$trackCount} tracks.");

        return self::SUCCESS;
    }

    protected function resolveLegacyRoot(): ?string
    {
        $candidates = array_filter([
            $this->option('path'),
            config('creative_ai.legacy_path'),
            base_path('legacy'),
            base_path('../legacy'),
            base_path('../'),
        ]);

        foreach ($candidates as $candidate) {
            $path = realpath($candidate);

            if (! $path) {
                continue;
            }

            if (is_dir($path.'/images/fulls') && is_dir($path.'/music')) {
                return $path;
            }
        }

        return null;
    }

    protected function importArtworks(string $legacyRoot, Collection $collection): int
    {
        $fullsPath = $legacyRoot.'/images/fulls';
        $thumbsPath = $legacyRoot.'/images/thumbs';
        $files = collect(File::files($fullsPath))
            ->filter(fn ($file) => strtolower($file->getExtension()) === 'jpg')
            ->sortBy(fn ($file) => $file->getFilename())
            ->values();

        $count = 0;
        $latestHero = null;

        foreach ($files as $file) {
            $filename = $file->getFilename();
            $number = (int) pathinfo($filename, PATHINFO_FILENAME);
            $originalPath = "artworks/originals/{$filename}";
            $displayPath = "artworks/display/{$filename}";
            $thumbPath = "artworks/thumbs/{$filename}";

            $this->copyPublicFile($file->getPathname(), $originalPath);
            $this->copyPublicFile($file->getPathname(), $displayPath);

            if (is_file($thumbsPath.'/'.$filename)) {
                $this->copyPublicFile($thumbsPath.'/'.$filename, $thumbPath);
            } else {
                $this->copyPublicFile($file->getPathname(), $thumbPath);
            }

            [$width, $height] = getimagesize($file->getPathname()) ?: [null, null];

            $artwork = Artwork::query()->updateOrCreate(
                ['original_filename' => $filename],
                [
                    'collection_id' => $collection->id,
                    'title' => 'Artwork '.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                    'slug' => 'artwork-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                    'description' => 'Generative Art - John Reijmer',
                    'image_path' => $originalPath,
                    'display_path' => $displayPath,
                    'thumb_path' => $thumbPath,
                    'width' => $width,
                    'height' => $height,
                    'sort_order' => $number,
                    'featured' => $number >= max(1, $files->count() - 5),
                    'published' => true,
                    'published_at' => now(),
                ],
            );
            $artwork->collections()->syncWithoutDetaching([$collection->id]);

            $latestHero = $thumbPath;
            $count++;
        }

        if ($latestHero) {
            $collection->update(['hero_image_path' => $latestHero]);
        }

        return $count;
    }

    protected function importTracks(string $legacyRoot): int
    {
        $musicPath = $legacyRoot.'/music';
        $playlist = Playlist::query()->firstOrCreate(
            ['slug' => 'creative-ai-radio'],
            [
                'title' => 'Creative-Ai Radio',
                'description' => 'A playlist of generated and experimental music from the Creative-Ai archive.',
                'featured' => true,
                'published' => true,
                'published_at' => now(),
            ],
        );

        $files = collect(File::files($musicPath))
            ->filter(fn ($file) => strtolower($file->getExtension()) === 'mp3')
            ->sortBy(fn ($file) => $file->getFilename())
            ->values();

        $count = 0;

        foreach ($files as $position => $file) {
            $filename = $file->getFilename();
            $label = $this->trackLabels[$filename] ?? [
                'title' => Str::headline(pathinfo($filename, PATHINFO_FILENAME)),
                'artist' => null,
            ];
            $audioPath = "tracks/audio/{$filename}";

            $this->copyPublicFile($file->getPathname(), $audioPath);

            $track = Track::query()->updateOrCreate(
                ['original_filename' => $filename],
                [
                    'title' => $label['title'],
                    'artist' => $label['artist'],
                    'slug' => Str::slug(($label['artist'] ? $label['artist'].' ' : '').$label['title']),
                    'audio_path' => $audioPath,
                    'sort_order' => $position + 1,
                    'featured' => $position < 4,
                    'published' => true,
                    'published_at' => now(),
                ],
            );

            PlaylistTrack::query()->updateOrCreate(
                ['playlist_id' => $playlist->id, 'track_id' => $track->id],
                ['position' => $position + 1],
            );

            $count++;
        }

        return $count;
    }

    protected function copyPublicFile(string $source, string $target): void
    {
        if (! $this->option('force') && Storage::disk('public')->exists($target)) {
            return;
        }

        Storage::disk('public')->put($target, File::get($source));
    }
}
