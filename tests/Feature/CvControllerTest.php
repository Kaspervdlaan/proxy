<?php

namespace Tests\Feature;

use App\Services\StoryblokCvService;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class CvControllerTest extends TestCase
{
    public function test_cv_endpoint_returns_story_payload(): void
    {
        $this->mock(StoryblokCvService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetchStoryWithCacheVersion')
                ->once()
                ->with('cv')
                ->andReturn([
                    'story' => ['name' => 'CV'],
                    'cv_used' => '123',
                    'cv_latest' => '124',
                ]);
        });

        $response = $this->getJson('/api/cv');

        $response
            ->assertOk()
            ->assertJson([
                'slug' => 'cv',
                'story' => ['name' => 'CV'],
                'cv' => [
                    'used' => '123',
                    'latest' => '124',
                ],
            ])
            ->assertJsonPath('fetched_at', fn (mixed $value) => is_string($value) && $value !== '');
    }

    public function test_cv_endpoint_returns_502_when_story_service_fails(): void
    {
        $this->mock(StoryblokCvService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetchStoryWithCacheVersion')
                ->once()
                ->with('cv')
                ->andThrow(new RuntimeException('Storyblok unavailable'));
        });

        $response = $this->getJson('/api/cv');

        $response
            ->assertStatus(502)
            ->assertJson([
                'message' => 'Unable to fetch story from Storyblok.',
            ]);
    }

    public function test_clear_endpoint_refreshes_cv_without_revalidate_call_when_url_is_missing(): void
    {
        config(['services.storyblok.next_revalidate_url' => '']);

        $this->mock(StoryblokCvService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refreshLatestCacheVersion')
                ->once()
                ->andReturn('200');
            $mock->shouldReceive('invalidateStoryContentCacheBySlug')
                ->once();
            $mock->shouldReceive('clearStoredCacheVersion')
                ->once();
        });

        $response = $this->postJson('/api/cv/cache/clear');

        $response
            ->assertOk()
            ->assertJson([
                'cv' => '200',
                'invalidate' => 'slug',
                'slug' => 'home',
                'next' => [
                    'requested' => false,
                ],
            ]);
    }

    public function test_clear_endpoint_invalidates_backend_slug_when_slug_provided(): void
    {
        config(['services.storyblok.next_revalidate_url' => '']);

        $this->mock(StoryblokCvService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refreshLatestCacheVersion')
                ->once()
                ->andReturn('210');
            $mock->shouldReceive('invalidateStoryContentCacheBySlug')
                ->once()
                ->with('about');
            $mock->shouldReceive('clearStoredCacheVersion')
                ->once();
        });

        $response = $this->postJson('/api/cv/cache/clear', [
            'slug' => 'about',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'cv' => '210',
                'invalidate' => 'slug',
                'slug' => 'about',
            ]);
    }

    public function test_webhook_endpoint_uses_payload_slug_and_refreshes_cv(): void
    {
        config(['services.storyblok.next_revalidate_url' => '']);

        $this->mock(StoryblokCvService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refreshLatestCacheVersion')
                ->once()
                ->andReturn('300');
            $mock->shouldReceive('invalidateStoryContentCacheBySlug')
                ->once()
                ->with('profile/jane-doe');
            $mock->shouldReceive('clearStoredCacheVersion')
                ->once();
        });

        $response = $this->postJson('/api/storyblok/webhook', [
            'action' => 'published',
            'story' => [
                'full_slug' => 'profile/jane-doe',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'action' => 'published',
                'slug' => 'profile/jane-doe',
                'cv' => '300',
                'invalidate' => 'slug',
                'next' => [
                    'requested' => false,
                ],
            ]);
    }
}
