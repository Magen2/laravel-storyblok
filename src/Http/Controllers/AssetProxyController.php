<?php

declare(strict_types=1);

namespace Riclep\Storyblok\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proxies asset requests to Storyblok CDN.
 */
class AssetProxyController extends Controller
{
    /**
     * Browser cache max-age in seconds (1 year for immutable assets).
     */
    protected const BROWSER_CACHE_MAX_AGE = 31536000;

    /**
     * Proxy an asset request to Storyblok.
     */
    public function proxy(Request $request, string $path): Response
    {
        $storyblokUrl = $this->buildStoryblokUrl($path);

        $cacheKey = 'storyblok_asset_' . md5($storyblokUrl);
        $cacheDuration = config('storyblok.asset_proxy_cache_duration', 60 * 24 * 7);

        // Use Redis/Valkey cache store for binary asset caching
        $cacheStore = Cache::store(config('storyblok.asset_proxy_cache_store', 'redis'));

        if (config('storyblok.asset_proxy_cache', true)) {
            $cachedResponse = $cacheStore->get($cacheKey);

            if ($cachedResponse) {
                return $this->createCachedResponse(
                    base64_decode($cachedResponse['body']),
                    $cachedResponse['content_type'],
                    'HIT'
                );
            }
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => '*/*',
                    'User-Agent' => 'StoryblokAssetProxy/1.0',
                ])
                ->get($storyblokUrl);

            if (!$response->successful()) {
                Log::warning('Asset proxy failed', [
                    'url' => $storyblokUrl,
                    'status' => $response->status(),
                ]);

                return response('Asset not found', 404);
            }

            $body = $response->body();
            $contentType = $response->header('Content-Type') ?? $this->guessContentType($path);

            if (config('storyblok.asset_proxy_cache', true)) {
                // Store body as base64 to handle binary data safely
                $cacheStore->put($cacheKey, [
                    'body' => base64_encode($body),
                    'content_type' => $contentType,
                ], now()->addMinutes($cacheDuration));
            }

            return $this->createCachedResponse($body, $contentType, 'MISS');
        } catch (\Exception $e) {
            Log::error('Asset proxy exception', [
                'url' => $storyblokUrl,
                'error' => $e->getMessage(),
            ]);

            return response('Failed to fetch asset', 502);
        }
    }

    /**
     * Create a response with optimized cache headers for Lighthouse scores.
     */
    protected function createCachedResponse(string $body, string $contentType, string $cacheStatus): Response
    {
        $maxAge = config('storyblok.asset_proxy_browser_cache', self::BROWSER_CACHE_MAX_AGE);
        $expiresDate = gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT';

        return response($body)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'public, max-age=' . $maxAge . ', immutable')
            ->header('Expires', $expiresDate)
            ->header('X-Cache', $cacheStatus)
            ->header('Vary', 'Accept-Encoding');
    }

    /**
     * Builds the full Storyblok CDN URL from the path.
     */
    protected function buildStoryblokUrl(string $path): string
    {
        $assetDomain = config('storyblok.asset_domain', 'a.storyblok.com');


        if (str_contains($path, '/m/')) {
            $parts = explode('/m/', $path, 2);
            $assetPath = $parts[0];
            $transforms = $parts[1];

            return 'https://' . $assetDomain . '/' . $assetPath . '/m/' . $transforms;
        }

        return 'https://' . $assetDomain . '/' . $path;
    }

    /**
     * Guesses the content type based on file extension.
     */
    protected function guessContentType(string $path): string
    {
        // Extract the base path without transforms
        $basePath = $path;
        if (str_contains($path, '/m/')) {
            $basePath = explode('/m/', $path, 2)[0];
        }

        $extension = strtolower(pathinfo($basePath, PATHINFO_EXTENSION));

        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'avif' => 'image/avif',
            'heic' => 'image/heic',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
