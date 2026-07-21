<?php

namespace Tests\Feature;

use App\Enums\JournalPlanningMode;
use App\Enums\PostMediaType;
use App\Filament\Pages\JournalPlanningConfiguration;
use App\Filament\Resources\SiteSettings\Pages\ManageSiteSettings;
use App\Filament\Resources\SiteSettings\SiteSettingResource;
use App\Models\PostTemplate;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\JournalPlanningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class JournalPlanningSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_typed_fail_closed_and_do_not_enable_automation(): void
    {
        $defaults = app(JournalPlanningSettings::class)->current();

        foreach (PostMediaType::cases() as $type) {
            $this->assertSame(JournalPlanningMode::Off, $defaults->sourceMode($type));
        }

        $this->assertSame(JournalPlanningMode::Off, $defaults->artworkBatchMode);
        $this->assertSame(JournalPlanningMode::Off, $defaults->albumImportMode);
        $this->assertFalse($defaults->hasAutomaticWorkflows());
        $this->assertNull($defaults->postTemplateId);
        $this->assertFalse($defaults->copySharedTags);
        $this->assertTrue($defaults->useSourceArtworkAsCover);
        $this->assertDatabaseMissing('site_settings', ['key' => JournalPlanningSettings::SETTING_KEY]);
    }

    public function test_valid_defaults_are_canonicalized_and_returned_as_typed_values(): void
    {
        $template = PostTemplate::query()->create(['name' => 'Release story']);
        $settings = app(JournalPlanningSettings::class);

        $defaults = $settings->save([
            'artwork_mode' => JournalPlanningMode::Ask->value,
            'album_mode' => JournalPlanningMode::Automatic->value,
            'artwork_batch_mode' => JournalPlanningMode::Ask->value,
            'album_import_mode' => JournalPlanningMode::Automatic->value,
            'post_template_id' => (string) $template->getKey(),
            'copy_shared_tags' => true,
            'use_source_artwork_as_cover' => false,
            'unknown_field' => 'not persisted',
        ]);

        $this->assertSame(JournalPlanningMode::Ask, $defaults->artworkMode);
        $this->assertSame(JournalPlanningMode::Automatic, $defaults->albumMode);
        $this->assertSame(JournalPlanningMode::Off, $defaults->trackMode);
        $this->assertTrue($defaults->hasAutomaticWorkflows());
        $this->assertSame($template->getKey(), $defaults->postTemplateId);
        $this->assertTrue($defaults->copySharedTags);
        $this->assertFalse($defaults->useSourceArtworkAsCover);
        $this->assertTrue($settings->template()?->is($template));

        $stored = SiteSetting::query()
            ->where('key', JournalPlanningSettings::SETTING_KEY)
            ->firstOrFail()
            ->value;

        $this->assertSame($defaults->toArray(), $stored);
        $this->assertArrayNotHasKey('unknown_field', $stored);
    }

    public function test_invalid_writes_are_rejected_and_corrupt_storage_falls_back_safely(): void
    {
        $inactive = PostTemplate::query()->create([
            'name' => 'Inactive template',
            'is_active' => false,
        ]);
        $settings = app(JournalPlanningSettings::class);

        try {
            $settings->save([
                'artwork_mode' => 'silently-publish',
                'post_template_id' => $inactive->getKey(),
            ]);
            $this->fail('Invalid Journal planning settings should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('artwork_mode', $exception->errors());
            $this->assertArrayHasKey('post_template_id', $exception->errors());
        }

        $this->assertDatabaseMissing('site_settings', ['key' => JournalPlanningSettings::SETTING_KEY]);

        SiteSetting::query()->create([
            'key' => JournalPlanningSettings::SETTING_KEY,
            'value' => [
                'artwork_mode' => 'unexpected',
                'collection_mode' => JournalPlanningMode::Ask->value,
                'album_import_mode' => ['automatic'],
                'post_template_id' => $inactive->getKey(),
                'copy_shared_tags' => 'not-a-boolean',
                'use_source_artwork_as_cover' => 0,
            ],
        ]);

        $defaults = $settings->refresh()->current();

        $this->assertSame(JournalPlanningMode::Off, $defaults->artworkMode);
        $this->assertSame(JournalPlanningMode::Ask, $defaults->collectionMode);
        $this->assertSame(JournalPlanningMode::Off, $defaults->albumImportMode);
        $this->assertFalse($defaults->hasAutomaticWorkflows());
        $this->assertNull($defaults->postTemplateId);
        $this->assertFalse($defaults->copySharedTags);
        $this->assertFalse($defaults->useSourceArtworkAsCover);
    }

    public function test_administrator_can_manage_defaults_from_the_dedicated_page(): void
    {
        $admin = User::factory()->admin()->create();
        $template = PostTemplate::query()->create(['name' => 'Artwork process']);

        Livewire::actingAs(User::factory()->create())
            ->test(JournalPlanningConfiguration::class)
            ->assertForbidden();

        Livewire::actingAs($admin)
            ->test(JournalPlanningConfiguration::class)
            ->assertFormSet([
                'artwork_mode' => JournalPlanningMode::Off->value,
                'artwork_batch_mode' => JournalPlanningMode::Off->value,
                'album_import_mode' => JournalPlanningMode::Off->value,
                'use_source_artwork_as_cover' => true,
            ])
            ->fillForm([
                'artwork_mode' => JournalPlanningMode::Ask->value,
                'collection_mode' => JournalPlanningMode::Off->value,
                'album_mode' => JournalPlanningMode::Automatic->value,
                'playlist_mode' => JournalPlanningMode::Off->value,
                'track_mode' => JournalPlanningMode::Ask->value,
                'artwork_batch_mode' => JournalPlanningMode::Ask->value,
                'album_import_mode' => JournalPlanningMode::Automatic->value,
                'post_template_id' => $template->getKey(),
                'copy_shared_tags' => true,
                'use_source_artwork_as_cover' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = SiteSetting::query()
            ->where('key', JournalPlanningSettings::SETTING_KEY)
            ->firstOrFail()
            ->value;

        $this->assertSame(JournalPlanningMode::Automatic->value, $stored['album_mode']);
        $this->assertSame(JournalPlanningMode::Automatic->value, $stored['album_import_mode']);
        $this->assertSame($template->getKey(), $stored['post_template_id']);
        $this->assertTrue($stored['copy_shared_tags']);
    }

    public function test_reserved_configuration_keys_are_hidden_and_rejected_by_generic_settings(): void
    {
        SiteSetting::query()->create(['key' => AiSettings::SETTING_KEY, 'value' => []]);
        SiteSetting::query()->create(['key' => JournalPlanningSettings::SETTING_KEY, 'value' => []]);
        SiteSetting::query()->create(['key' => 'home_intro', 'value' => ['title' => 'Welcome']]);

        $this->assertSame(
            ['home_intro'],
            SiteSettingResource::getEloquentQuery()->orderBy('key')->pluck('key')->all(),
        );

        SiteSetting::query()->where('key', JournalPlanningSettings::SETTING_KEY)->delete();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageSiteSettings::class)
            ->callAction('create', [
                'key' => JournalPlanningSettings::SETTING_KEY,
                'value' => ['unexpected' => 'replacement'],
            ])
            ->assertHasActionErrors(['key']);

        $this->assertDatabaseMissing('site_settings', ['key' => JournalPlanningSettings::SETTING_KEY]);
    }
}
