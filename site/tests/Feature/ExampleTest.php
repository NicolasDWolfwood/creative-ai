<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_showcase_homepage_renders_without_published_media(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Creative-Ai');
        $response->assertSee('No published artwork yet.');
    }
}
