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
        $slug = (string) config('services.storyblok.cv_slug', 'cv');
        $ttlSeconds = max((int) config('services.storyblok.cache_ttl_seconds', 900), 30);
        $cacheKey = "cv:html:{$slug}";

        $fromCache = Cache::has($cacheKey);

        $result = Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () use ($slug) {
            $story = $this->storyblokCvService->fetchStory($slug);
            $html = $this->storyblokCvService->renderHtml($story);

            return [
                'slug' => $slug,
                'html' => $html,
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

        $slug = (string) config('services.storyblok.cv_slug', 'cv');
        Cache::forget("cv:html:{$slug}");

        return response()->json([
            'message' => 'CV cache cleared.',
            'slug' => $slug,
        ]);
    }

    public function staleSafe(): JsonResponse
    {
        $slug = (string) config('services.storyblok.cv_slug', 'cv');
        $cacheKey = "cv:html:{$slug}";

        try {
            return $this->show();
        } catch (Throwable $exception) {
            Log::warning('Serving stale CV cache after Storyblok fetch failure.', [
                'exception' => $exception->getMessage(),
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
}
