<?php

namespace Tests\Feature;

use App\Filament\Pages\HomepageContent;
use App\Models\Collection;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\ArtworkSignatureSettings;
use App\Services\JournalPlanningSettings;
use App\Services\SiteContentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SiteContentSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_or_malformed_content_uses_one_canonical_default_without_writing(): void
    {
        $settings = app(SiteContentSettings::class);

        $this->assertSame(SiteContentSettings::DEFAULT_HOME_INTRO, $settings->homeIntro());
        $this->assertDatabaseMissing('site_settings', ['key' => SiteContentSettings::SETTING_KEY]);

        foreach ([
            null,
            [],
            ['title' => 'Unused legacy heading'],
            ['body' => null],
            ['body' => ['not' => 'text']],
            ['body' => '   '],
        ] as $value) {
            SiteSetting::query()->updateOrCreate(
                ['key' => SiteContentSettings::SETTING_KEY],
                ['value' => $value],
            );

            $this->assertSame(
                SiteContentSettings::DEFAULT_HOME_INTRO,
                $settings->refresh()->homeIntro(),
            );
        }
    }

    public function test_dedicated_page_is_clear_admin_only_and_has_no_key_editor(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(HomepageContent::class)
            ->assertForbidden();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(HomepageContent::class)
            ->assertOk()
            ->assertSee('One public introduction')
            ->assertSee('Saving publishes this text immediately')
            ->assertFormSet([
                'body' => SiteContentSettings::DEFAULT_HOME_INTRO,
            ])
            ->assertFormFieldDoesNotExist('key');
    }

    public function test_page_saves_only_homepage_content_and_preserves_existing_setting_data(): void
    {
        SiteSetting::query()->create([
            'key' => AiSettings::SETTING_KEY,
            'value' => ['provider' => 'ollama'],
        ]);
        SiteSetting::query()->create([
            'key' => ArtworkSignatureSettings::SETTING_KEY,
            'value' => ['default_mode' => 'automatic'],
        ]);
        SiteSetting::query()->create([
            'key' => JournalPlanningSettings::SETTING_KEY,
            'value' => ['artwork_mode' => 'off'],
        ]);
        SiteSetting::query()->create([
            'key' => 'unused_legacy_key',
            'value' => ['kept' => true],
        ]);
        SiteSetting::query()->create([
            'key' => SiteContentSettings::SETTING_KEY,
            'value' => [
                'title' => 'Legacy heading',
                'body' => 'Former introduction.',
                'future_field' => 'Preserve for rollback compatibility',
            ],
        ]);

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(HomepageContent::class)
            ->assertFormSet(['body' => 'Former introduction.'])
            ->fillForm(['body' => 'A clearer introduction to this creative archive.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $component
            ->fillForm(['body' => 'The final introduction.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('site_settings', 5);
        $this->assertSame(
            [
                'title' => 'Legacy heading',
                'body' => 'The final introduction.',
                'future_field' => 'Preserve for rollback compatibility',
            ],
            SiteSetting::query()->where('key', SiteContentSettings::SETTING_KEY)->firstOrFail()->value,
        );
        $this->assertSame(
            ['provider' => 'ollama'],
            SiteSetting::query()->where('key', AiSettings::SETTING_KEY)->firstOrFail()->value,
        );
        $this->assertSame(
            ['kept' => true],
            SiteSetting::query()->where('key', 'unused_legacy_key')->firstOrFail()->value,
        );
    }

    public function test_invalid_content_is_rejected_without_replacing_the_saved_text(): void
    {
        $settings = app(SiteContentSettings::class);
        $settings->save(['body' => 'Keep this introduction.']);

        foreach ([
            '',
            str_repeat('x', SiteContentSettings::MAX_HOME_INTRO_LENGTH + 1),
        ] as $invalidBody) {
            try {
                $settings->save(['body' => $invalidBody]);
                $this->fail('Invalid homepage content should be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('body', $exception->errors());
            }
        }

        $this->assertSame(
            'Keep this introduction.',
            SiteSetting::query()->where('key', SiteContentSettings::SETTING_KEY)->firstOrFail()->value['body'],
        );
    }

    public function test_saved_introduction_drives_public_showcase_text_and_metadata(): void
    {
        $intro = 'A focused introduction for the public creative archive.';
        app(SiteContentSettings::class)->save(['body' => $intro]);

        $this->get('/')
            ->assertOk()
            ->assertSee('<p class="hero-intro">'.$intro.'</p>', escape: false)
            ->assertSee('<meta name="description" content="'.$intro.'">', escape: false);

        $this->get('/gallery')
            ->assertOk()
            ->assertSee('<p class="hero-intro">'.$intro.'</p>', escape: false);

        $collection = Collection::query()->create([
            'title' => 'Quiet Systems',
            'description' => 'A description written specifically for this collection.',
            'published' => true,
        ]);

        $this->get(route('collections.show', $collection))
            ->assertOk()
            ->assertSee('A description written specifically for this collection.')
            ->assertDontSee($intro);
    }
}
