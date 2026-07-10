<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_requires_database_redis_and_writable_storage(): void
    {
        $dataConnection = Mockery::mock();
        $dataConnection->shouldReceive('ping')->once()->andReturn(true);
        $cacheConnection = Mockery::mock();
        $cacheConnection->shouldReceive('ping')->once()->andReturn(true);
        Redis::shouldReceive('connection')->once()->withNoArgs()->andReturn($dataConnection);
        Redis::shouldReceive('connection')->once()->with('cache')->andReturn($cacheConnection);

        $this->get('/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ready']);
    }

    public function test_readiness_failure_does_not_expose_dependency_details(): void
    {
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('private connection detail'));

        $this->get('/ready')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable'])
            ->assertDontSee('private connection detail');
    }
}
