<?php

declare(strict_types=1);

namespace Riclep\Storyblok\Support\ImageTransformers;

use Illuminate\Support\Str;

/**
 * Generates local proxy URLs for Storyblok assets.
 */
class StoryblokProxy extends Storyblok
{
    /**
     * The local proxy base path.
     */
    protected string $proxyBasePath = '/assets';

    /**
     * Creates the local proxy URL for the image.
     */
    public function buildUrl(): string
    {
        if ($this->transformations === 'svg') {
            return $this->buildProxyUrl($this->image->content()['filename']);
        }

        $transforms = '';

        if (array_key_exists('fit-in', $this->transformations)) {
            $transforms .= '/fit-in';
        }

        if (array_key_exists('width', $this->transformations)) {
            $transforms .= '/' . $this->transformations['width'] . 'x' . $this->transformations['height'];
        }

        if (array_key_exists('focus', $this->transformations) && $this->transformations['focus'] === 'smart') {
            $transforms .= '/smart';
        }

        if ($this->hasFilters()) {
            $transforms .= $this->applyFilters();
        }

        return $this->buildProxyUrl($this->image->content()['filename'], $transforms);
    }

    /**
     * Builds a local proxy URL from the Storyblok asset path.
     */
    protected function buildProxyUrl(string $filename, ?string $transforms = null): string
    {
        $path = $this->extractAssetPath($filename);

        if ($transforms) {
            return $this->proxyBasePath . '/' . $path . '/m' . $transforms;
        }

        return $this->proxyBasePath . '/' . $path;
    }

    /**
     * Extracts the asset path from a full Storyblok URL.
     */
    protected function extractAssetPath(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? $url;

        $path = ltrim($path, '/');

        if (Str::startsWith($path, 'f/')) {
            return $path;
        }

        if (preg_match('#f/\d+/.+$#', $path, $matches)) {
            return $matches[0];
        }

        return $path;
    }

    /**
     * Override assetDomain to use proxy URLs.
     */
    protected function assetDomain($options = null): string
    {
        $filename = $this->image->content()['filename'];

        if ($options) {
            return $this->buildProxyUrl($filename, $options);
        }

        return $this->buildProxyUrl($filename);
    }
}
