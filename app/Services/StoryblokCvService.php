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
        $normalizedSlug = $this->normalizeSlug($slug);
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
            $cachedStory = $this->getContentFromCache($normalizedSlug, $storedCv);

            if ($cachedStory !== null) {
                return [
                    'story' => $cachedStory,
                    'cv_used' => $storedCv,
                    'cv_latest' => $storedCv,
                    'cache_hit' => true,
                    'cache_stale' => false,
                ];
            }

            $query['cv'] = $storedCv;
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get("{$base}/stories/{$normalizedSlug}", $query);

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

            $cacheCv = $latestCvValue ?: $storedCv;

            if ($cacheCv !== null && $cacheCv !== '') {
                $this->storeContentCache($normalizedSlug, $cacheCv, $story);
            }

            return [
                'story' => $story,
                'cv_used' => $storedCv,
                'cv_latest' => $latestCvValue,
                'cache_hit' => false,
                'cache_stale' => false,
            ];
        } catch (RuntimeException $exception) {
            if (! $this->shouldServeStaleOnError()) {
                throw $exception;
            }

            $staleEntry = $this->getStaleContent($normalizedSlug);

            if ($staleEntry === null) {
                throw $exception;
            }

            return [
                'story' => Arr::get($staleEntry, 'story'),
                'cv_used' => Arr::get($staleEntry, 'cv'),
                'cv_latest' => Arr::get($staleEntry, 'cv'),
                'stale' => true,
                'cache_hit' => false,
                'cache_stale' => true,
            ];
        }
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

    public function invalidateStoryContentCacheBySlug(string $slug): void
    {
        $normalizedSlug = $this->normalizeSlug($slug);
        $cachedCvs = $this->getCachedCvsForSlug($normalizedSlug);

        foreach ($cachedCvs as $cv) {
            Cache::forget($this->contentCacheKey($normalizedSlug, $cv));
        }

        Cache::forget($this->slugCvRegistryKey($normalizedSlug));
        Cache::forget($this->staleContentCacheKey($normalizedSlug));
        $this->removeSlugFromRegistry($normalizedSlug);
    }

    private function storeCacheVersion(string $cv): void
    {
        Cache::forever($this->cacheVersionKey(), $cv);
    }

    private function getContentFromCache(string $slug, string $cv): ?array
    {
        $value = Cache::get($this->contentCacheKey($slug, $cv));

        return is_array($value) ? $value : null;
    }

    private function storeContentCache(string $slug, string $cv, array $story): void
    {
        Cache::put($this->contentCacheKey($slug, $cv), $story, $this->contentCacheTtlSeconds());
        Cache::put($this->staleContentCacheKey($slug), [
            'story' => $story,
            'cv' => $cv,
        ], $this->staleCacheTtlSeconds());

        $this->addSlugToRegistry($slug);
        $this->addCvToSlugRegistry($slug, $cv);
    }

    private function getStaleContent(string $slug): ?array
    {
        $value = Cache::get($this->staleContentCacheKey($slug));

        return is_array($value) ? $value : null;
    }

    private function contentCacheKey(string $slug, string $cv): string
    {
        return $this->contentCachePrefix().':'.$slug.':cv:'.$cv;
    }

    private function staleContentCacheKey(string $slug): string
    {
        return $this->contentCachePrefix().':'.$slug.':stale';
    }

    private function slugRegistryKey(): string
    {
        return $this->contentCachePrefix().':slugs';
    }

    private function slugCvRegistryKey(string $slug): string
    {
        return $this->contentCachePrefix().':'.$slug.':cvs';
    }

    private function getCachedSlugs(): array
    {
        $value = Cache::get($this->slugRegistryKey(), []);

        return is_array($value) ? array_values(array_unique(array_filter($value, 'is_string'))) : [];
    }

    private function addSlugToRegistry(string $slug): void
    {
        $slugs = $this->getCachedSlugs();

        if (! in_array($slug, $slugs, true)) {
            $slugs[] = $slug;
            Cache::forever($this->slugRegistryKey(), $slugs);
        }
    }

    private function removeSlugFromRegistry(string $slug): void
    {
        $slugs = array_values(array_filter($this->getCachedSlugs(), fn (string $existingSlug): bool => $existingSlug !== $slug));

        if ($slugs === []) {
            Cache::forget($this->slugRegistryKey());

            return;
        }

        Cache::forever($this->slugRegistryKey(), $slugs);
    }

    private function getCachedCvsForSlug(string $slug): array
    {
        $value = Cache::get($this->slugCvRegistryKey($slug), []);

        return is_array($value) ? array_values(array_unique(array_filter($value, 'is_string'))) : [];
    }

    private function addCvToSlugRegistry(string $slug, string $cv): void
    {
        $cvs = $this->getCachedCvsForSlug($slug);

        if (! in_array($cv, $cvs, true)) {
            $cvs[] = $cv;
            Cache::forever($this->slugCvRegistryKey($slug), $cvs);
        }
    }

    private function normalizeSlug(string $slug): string
    {
        $normalizedSlug = trim($slug, '/');
        $rootSlug = trim((string) config('services.storyblok.root_slug', 'home'), '/');

        if ($normalizedSlug === '') {
            return $rootSlug !== '' ? $rootSlug : 'home';
        }

        return $normalizedSlug;
    }

    private function shouldServeStaleOnError(): bool
    {
        return (bool) config('services.storyblok.serve_stale_on_error', true);
    }

    private function contentCachePrefix(): string
    {
        return (string) config('services.storyblok.content_cache_prefix', 'storyblok:story');
    }

    private function contentCacheTtlSeconds(): int
    {
        return max(1, (int) config('services.storyblok.content_cache_ttl_seconds', 300));
    }

    private function staleCacheTtlSeconds(): int
    {
        return max(1, (int) config('services.storyblok.stale_cache_ttl_seconds', 86400));
    }

    private function cacheVersionKey(): string
    {
        return (string) config('services.storyblok.cache_version_key', 'storyblok:cv:latest');
    }
}
