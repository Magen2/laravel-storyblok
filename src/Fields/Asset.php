<?php


namespace Riclep\Storyblok\Fields;


use Illuminate\Support\Str;
use Riclep\Storyblok\Field;

/**
 * @property false|string filename
 */
class Asset extends Field
{
	public function __construct($content, $block)
	{
		parent::__construct($content, $block);

		if (isset($this->content['filename'])) {
			$this->content['filename'] = str_replace('a.storyblok.com', config('storyblok.asset_domain'), $this->content['filename']);

			if (config('storyblok.asset_proxy_enabled', false)) {
				$this->content['filename'] = $this->buildProxyUrl($this->content['filename']);
			}
        }
	}

	public function __toString(): string
	{
		if ($this->content['filename']) {
			return $this->content['filename'];
		}

		return '';
	}

	/**
	 * Checks a file was uploaded
	 *
	 * @return bool
	 */
	public function hasFile(): bool
	{
		if (!array_key_exists('filename', $this->content)) {
			return false;
		}

		return (bool) $this->content['filename'];
	}

	/**
	 * Builds a local proxy URL from the Storyblok asset URL.
	 */
	protected function buildProxyUrl(string $url): string
	{
		$proxyBasePath = config('storyblok.asset_proxy_path', '/assets');
		$path = $this->extractAssetPath($url);

		return $proxyBasePath . '/' . $path;
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
}
