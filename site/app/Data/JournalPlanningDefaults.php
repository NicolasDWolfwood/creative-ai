<?php

namespace App\Data;

use App\Enums\JournalPlanningMode;
use App\Enums\PostMediaType;

final readonly class JournalPlanningDefaults
{
    public function __construct(
        public JournalPlanningMode $artworkMode,
        public JournalPlanningMode $collectionMode,
        public JournalPlanningMode $albumMode,
        public JournalPlanningMode $playlistMode,
        public JournalPlanningMode $trackMode,
        public JournalPlanningMode $artworkBatchMode,
        public JournalPlanningMode $albumImportMode,
        public ?int $postTemplateId,
        public bool $copySharedTags,
        public bool $useSourceArtworkAsCover,
    ) {}

    public static function disabled(): self
    {
        return new self(
            artworkMode: JournalPlanningMode::Off,
            collectionMode: JournalPlanningMode::Off,
            albumMode: JournalPlanningMode::Off,
            playlistMode: JournalPlanningMode::Off,
            trackMode: JournalPlanningMode::Off,
            artworkBatchMode: JournalPlanningMode::Off,
            albumImportMode: JournalPlanningMode::Off,
            postTemplateId: null,
            copySharedTags: false,
            useSourceArtworkAsCover: true,
        );
    }

    public function sourceMode(PostMediaType $type): JournalPlanningMode
    {
        return match ($type) {
            PostMediaType::Artwork => $this->artworkMode,
            PostMediaType::Collection => $this->collectionMode,
            PostMediaType::Album => $this->albumMode,
            PostMediaType::Playlist => $this->playlistMode,
            PostMediaType::Track => $this->trackMode,
        };
    }

    public function hasAutomaticWorkflows(): bool
    {
        return collect($this->modes())->contains(
            fn (JournalPlanningMode $mode): bool => $mode->isAutomatic(),
        );
    }

    /** @return array<string, int|string|bool|null> */
    public function toArray(): array
    {
        return [
            'artwork_mode' => $this->artworkMode->value,
            'collection_mode' => $this->collectionMode->value,
            'album_mode' => $this->albumMode->value,
            'playlist_mode' => $this->playlistMode->value,
            'track_mode' => $this->trackMode->value,
            'artwork_batch_mode' => $this->artworkBatchMode->value,
            'album_import_mode' => $this->albumImportMode->value,
            'post_template_id' => $this->postTemplateId,
            'copy_shared_tags' => $this->copySharedTags,
            'use_source_artwork_as_cover' => $this->useSourceArtworkAsCover,
        ];
    }

    /** @return list<JournalPlanningMode> */
    private function modes(): array
    {
        return [
            $this->artworkMode,
            $this->collectionMode,
            $this->albumMode,
            $this->playlistMode,
            $this->trackMode,
            $this->artworkBatchMode,
            $this->albumImportMode,
        ];
    }
}
