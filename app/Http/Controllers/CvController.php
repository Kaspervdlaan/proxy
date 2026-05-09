<?php

namespace App\Http\Controllers;

use App\Services\StoryblokCvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
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
        $result = $this->storyblokCvService->fetchStoryWithCacheVersion($slug);

        return response()->json([
            'slug' => $slug,
            'story' => Arr::get($result, 'story'),
            'cv' => [
                'used' => Arr::get($result, 'cv_used'),
                'latest' => Arr::get($result, 'cv_latest'),
            ],
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    public function clear(): JsonResponse
    {
        $cv = $this->storyblokCvService->refreshLatestCacheVersion();
        $revalidate = $this->notifyNextRevalidate([
            'source' => 'manual-cache-clear',
            'invalidate' => 'global',
            'cv' => $cv,
        ]);

        return response()->json([
            'message' => 'Cache version refreshed and Next revalidation requested.',
            'cv' => $cv,
            'next' => $revalidate,
        ]);
    }

    public function staleSafe(): JsonResponse
    {
        try {
            return $this->show();
        } catch (Throwable $exception) {
            Log::warning('Story fetch failed and no JSON cache fallback is configured.', [
                'exception' => $exception->getMessage(),
                'slug' => $this->resolveSlug(),
            ]);

            return response()->json([
                'message' => 'Unable to fetch story from Storyblok.',
            ], 502);
        }
    }

    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $action = (string) Arr::get($payload, 'action', 'unknown');
        $fullSlug = trim((string) Arr::get($payload, 'story.full_slug', ''), '/');
        $slug = $fullSlug !== '' ? $fullSlug : (string) config('services.storyblok.root_slug', 'home');

        $cv = $this->storyblokCvService->refreshLatestCacheVersion();
        $revalidate = $this->notifyNextRevalidate([
            'source' => 'storyblok-webhook',
            'action' => $action,
            'slug' => $slug,
            'invalidate' => 'global',
            'cv' => $cv,
            'space_id' => Arr::get($payload, 'space_id'),
        ]);

        return response()->json([
            'message' => 'Webhook accepted.',
            'action' => $action,
            'slug' => $slug,
            'cv' => $cv,
            'next' => $revalidate,
        ]);
    }

    private function resolveSlug(): string
    {
        $slug = (string) request()->query('slug', config('services.storyblok.cv_slug', 'home'));
        $normalized = trim($slug, '/');

        if ($normalized === '') {
            return (string) config('services.storyblok.root_slug', 'home');
        }

        return $normalized;
    }

    private function notifyNextRevalidate(array $payload): array
    {
        $url = (string) config('services.storyblok.next_revalidate_url', '');

        if ($url === '') {
            return [
                'requested' => false,
                'reason' => 'NEXT_REVALIDATE_URL is not configured.',
            ];
        }

        try {
            $request = Http::timeout(5)->acceptJson();

            $response = $request->post($url, $payload);

            return [
                'requested' => true,
                'status' => $response->status(),
                'ok' => $response->successful(),
            ];
        } catch (Throwable $exception) {
            Log::warning('Failed to call Next revalidation endpoint.', [
                'exception' => $exception->getMessage(),
                'url' => $url,
            ]);

            return [
                'requested' => true,
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
