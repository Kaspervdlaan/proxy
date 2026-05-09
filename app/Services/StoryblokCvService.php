<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StoryblokCvService
{
    public function fetchStoryWithCacheVersion(string $slug): array
    {
        $token = (string) config('services.storyblok.api_token');

        if ($token === '') {
            throw new RuntimeException('STORYBLOK_API_TOKEN is not configured.');
        }

        $base = rtrim((string) config('services.storyblok.api_base'), '/');
        $version = (string) config('services.storyblok.version');
        $storedCv = $this->getStoredCacheVersion();

        $query = [
            'token' => $token,
            'version' => $version,
        ];

        if ($storedCv !== null) {
            $query['cv'] = $storedCv;
        }

        $response = Http::timeout(10)
            ->acceptJson()
            ->get("{$base}/stories/{$slug}", $query);

        if ($response->failed()) {
            throw new RuntimeException('Storyblok request failed with status '.$response->status());
        }

        $payload = $response->json();
        $story = Arr::get($payload, 'story');

        if (! is_array($story)) {
            throw new RuntimeException('Storyblok response does not include story data.');
        }

        $latestCv = Arr::get($payload, 'cv');
        $latestCvValue = is_scalar($latestCv) ? (string) $latestCv : null;

        if ($latestCvValue !== null && $latestCvValue !== '') {
            $this->storeCacheVersion($latestCvValue);
        }

        return [
            'story' => $story,
            'cv_used' => $storedCv,
            'cv_latest' => $latestCvValue,
        ];
    }

    public function refreshLatestCacheVersion(): string
    {
        $token = (string) config('services.storyblok.api_token');

        if ($token === '') {
            throw new RuntimeException('STORYBLOK_API_TOKEN is not configured.');
        }

        $base = rtrim((string) config('services.storyblok.api_base'), '/');

        $response = Http::timeout(10)
            ->acceptJson()
            ->get("{$base}/spaces/me", [
                'token' => $token,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Unable to fetch Storyblok cache version (status '.$response->status().').');
        }

        $payload = $response->json();
        $version = Arr::get($payload, 'space.version');
        $cv = is_scalar($version) ? (string) $version : '';

        if ($cv === '') {
            throw new RuntimeException('Storyblok spaces endpoint did not return a cache version.');
        }

        $this->storeCacheVersion($cv);

        return $cv;
    }

    public function getStoredCacheVersion(): ?string
    {
        $value = Cache::get($this->cacheVersionKey());

        if (! is_scalar($value)) {
            return null;
        }

        $cv = (string) $value;

        return $cv === '' ? null : $cv;
    }

    private function storeCacheVersion(string $cv): void
    {
        Cache::forever($this->cacheVersionKey(), $cv);
    }

    private function cacheVersionKey(): string
    {
        return (string) config('services.storyblok.cache_version_key', 'storyblok:cv:latest');
    }
}
