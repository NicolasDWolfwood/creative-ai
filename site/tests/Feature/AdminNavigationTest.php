<?php

namespace Tests\Feature;

use App\Filament\Pages\AiConfiguration;
use App\Filament\Pages\ArtworkSignatureConfiguration;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\HomepageContent;
use App\Filament\Pages\JournalPlanningConfiguration;
use App\Filament\Pages\StoryOpportunities;
use App\Filament\Resources\AiQueues\AiQueueResource;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Artworks\ArtworkResource;
use App\Filament\Resources\Collections\CollectionResource;
use App\Filament\Resources\JournalAiRuns\JournalAiRunResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\PostTemplates\PostTemplateResource;
use App\Filament\Resources\Tracks\TrackResource;
use Filament\Facades\Filament;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    public function test_sidebar_follows_the_publication_workflow_and_keeps_configuration_last(): void
    {
        $expected = [
            'Showcase' => [
                [HomepageContent::class, 'Homepage', 5],
                [ArtworkResource::class, 'Artworks', 10],
                [CollectionResource::class, 'Collections', 20],
                [ArtworkSignatureConfiguration::class, 'Artwork signatures', 30],
            ],
            'Music' => [
                [TrackResource::class, 'Tracks', 10],
                [AlbumResource::class, 'Albums', 20],
                [PlaylistResource::class, 'Playlists', 30],
            ],
            'Journal' => [
                [PostResource::class, 'Posts', 10],
                [StoryOpportunities::class, 'Story opportunities', 20],
                [PostTemplateResource::class, 'Templates', 30],
                [JournalPlanningConfiguration::class, 'Planning defaults', 40],
            ],
            'AI & Automation' => [
                [AiQueueResource::class, 'Artwork AI queue', 10],
                [JournalAiRunResource::class, 'Journal AI jobs', 20],
                [AiConfiguration::class, 'AI providers', 30],
            ],
        ];

        $this->assertSame(array_keys($expected), Filament::getPanel('admin')->getNavigationGroups());
        $this->assertNull(Dashboard::getNavigationGroup());
        $this->assertSame('Studio', Dashboard::getNavigationLabel());
        $this->assertSame(-100, Dashboard::getNavigationSort());

        foreach ($expected as $group => $items) {
            foreach ($items as [$class, $label, $sort]) {
                $this->assertSame($group, $class::getNavigationGroup(), $label.' belongs in '.$group.'.');
                $this->assertSame($label, $class::getNavigationLabel());
                $this->assertSame($sort, $class::getNavigationSort());
            }
        }
    }
}
