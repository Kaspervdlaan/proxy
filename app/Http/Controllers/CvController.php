<?php

namespace App\Http\Controllers;

use App\Services\StoryblokCvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CvController extends Controller
{
    public function __construct(private readonly StoryblokCvService $storyblokCvService)
    {
    }

    public function show(): JsonResponse
    {
        $slug = $this->resolveSlug();
        $ttlSeconds = max((int) config('services.storyblok.cache_ttl_seconds', 900), 30);
        $cacheKey = $this->cacheKey($slug);

        $fromCache = Cache::has($cacheKey);

        $result = Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () use ($slug) {
            $story = $this->storyblokCvService->fetchStory($slug);

            return [
                'slug' => $slug,
                'story' => $story,
                'fetched_at' => now()->toIso8601String(),
            ];
        });

        return response()->json([
            ...$result,
            'cache' => [
                'hit' => $fromCache,
                'ttl_seconds' => $ttlSeconds,
            ],
        ]);
    }

    public function clear(): JsonResponse
    {
        $token = (string) env('CV_CACHE_BUST_TOKEN', '');
        $provided = (string) request()->header('X-Cache-Bust-Token', '');

        if ($token === '' || ! hash_equals($token, $provided)) {
            return response()->json([
                'message' => 'Unauthorized cache bust request.',
            ], 401);
        }

        $slug = $this->resolveSlug();
        Cache::forget($this->cacheKey($slug));

        return response()->json([
            'message' => 'Story cache cleared.',
            'slug' => $slug,
        ]);
    }

    public function staleSafe(): JsonResponse
    {
        $slug = $this->resolveSlug();
        $cacheKey = $this->cacheKey($slug);

        try {
            return $this->show();
        } catch (Throwable $exception) {
            Log::warning('Serving stale story cache after Storyblok fetch failure.', [
                'exception' => $exception->getMessage(),
                'slug' => $slug,
            ]);

            if (! Cache::has($cacheKey)) {
                return response()->json([
                    'message' => 'Unable to fetch CV and no cache is available.',
                ], 502);
            }

            $result = Cache::get($cacheKey);

            return response()->json([
                ...$result,
                'cache' => [
                    'hit' => true,
                    'stale' => true,
                ],
            ], 200);
        }
    }

    private function resolveSlug(): string
    {
        $slug = (string) request()->query('slug', config('services.storyblok.cv_slug', 'home'));
        $normalized = trim($slug, '/');

        if ($normalized === '') {
            return 'home';
        }

        return $normalized;
    }

    private function cacheKey(string $slug): string
    {
        return 'story:json:'.$slug;
    }
}
