<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
        config()->set('app.url', 'https://www.creative-ai.nl');
        config()->set('creative_ai.allow_indexing', true);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertSee('Disallow: /admin', escape: false)
            ->assertSee('Sitemap: https://www.creative-ai.nl/sitemap.xml', escape: false);
    }
}
