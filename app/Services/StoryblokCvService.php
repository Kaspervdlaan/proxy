<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StoryblokCvService
{
    public function fetchStory(string $slug): array
    {
        $token = (string) config('services.storyblok.api_token');

        if ($token === '') {
            throw new RuntimeException('STORYBLOK_API_TOKEN is not configured.');
        }

        $base = rtrim((string) config('services.storyblok.api_base'), '/');
        $version = (string) config('services.storyblok.version');

        $response = Http::timeout(10)
            ->acceptJson()
            ->get("{$base}/stories/{$slug}", [
                'token' => $token,
                'version' => $version,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Storyblok request failed with status '.$response->status());
        }

        $payload = $response->json();
        $story = Arr::get($payload, 'story');

        if (! is_array($story)) {
            throw new RuntimeException('Storyblok response does not include story data.');
        }

        return $story;
    }

    public function renderHtml(array $story): string
    {
        $name = (string) Arr::get($story, 'name', 'CV');
        $content = Arr::get($story, 'content', []);

        if (is_array($content)) {
            $candidates = [
                Arr::get($content, 'cv_html'),
                Arr::get($content, 'html'),
                Arr::get($content, 'content'),
            ];

            foreach ($candidates as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return $candidate;
                }
            }
        }

        return sprintf(
            '<article><h1>%s</h1><pre>%s</pre></article>',
            e($name),
            e(json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
        );
    }
}
