<?php


namespace Riclep\Storyblok;


use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Storyblok\Api\StoriesApi;
use Storyblok\Api\Request\StoryRequest;
use Storyblok\Api\Domain\Value\Resolver\RelationCollection;
use Storyblok\Api\Domain\Value\Resolver\ResolveLinks;
use Storyblok\Api\Domain\Value\Resolver\ResolveLinksLevel;
use Storyblok\Api\Domain\Value\Resolver\ResolveLinksType;
use Storyblok\Api\Domain\Value\Uuid;
use Storyblok\ApiException;

class RequestStory
{
	/**
	 * @var string|null A comma delimited string of relations to resolve matching: component_name.field_name
	 * @see https://www.storyblok.com/tp/using-relationship-resolving-to-include-other-content-entries
	 */
	protected ?string $resolveRelations = null;


	/**
	 * @var string|null The language version of the Story to load
	 * @see https://www.storyblok.com/docs/guide/in-depth/internationalization
	 */
	protected ?string $language = null;

	/**
	 * @var string|null The fallback language version of the Story to load
	 * @see https://www.storyblok.com/docs/guide/in-depth/internationalization
	 */
	protected ?string $fallbackLanguage = null;

	/**
	 * Caches the response if needed
	 *
	 * @param $slugOrUuid
	 * @return mixed
	 * @throws ApiException
	 */
	public function get($slugOrUuid): mixed
	{
		if (request()->has('_storyblok') || !config('storyblok.cache')) {
			$response = $this->makeRequest($slugOrUuid);
		} else {
            $cache = Cache::store(config('storyblok.sb_cache_driver'));

            if ($cache instanceof Illuminate\Cache\TaggableStore) {
                $cache = $cache->tags('storyblok');
            }

            $api_hash = md5(config('storyblok.api_public_key') ?? config('storyblok.api_preview_key'));

            $response = $cache->remember($slugOrUuid . '_' . $api_hash, config('storyblok.cache_duration') * 60, function () use ($slugOrUuid) {
                return $this->makeRequest($slugOrUuid);
            });
		}

		return $response['story'];
	}

	/**
	 * Prepares the relations so the format is correct for the API call
	 *
	 * @param $resolveRelations
	 */
	public function resolveRelations($resolveRelations): void
	{
		$this->resolveRelations = implode(',', $resolveRelations);
	}

	/**
	 * Set the language and fallback language to use for this Story, will default to ‘default’
	 *
	 * @param string|null $language
	 * @param string|null $fallbackLanguage
	 */
	public function language($language, $fallbackLanguage = null) {
		$this->language = $language;
		$this->fallbackLanguage = $fallbackLanguage;
	}

	/**
	 * Makes the API request
	 *
	 * @param $slugOrUuid
	 * @return array
	 * @throws ApiException
	 */
	private function makeRequest($slugOrUuid): array
	{
		/** @var \Riclep\Storyblok\ContentApi $contentApi */
		$contentApi = resolve(\Riclep\Storyblok\ContentApi::class);

		$options = [
			'language' => $this->language ?? 'default',
		];

		if ($this->resolveRelations) {
			$options['resolve_relations'] = explode(',', $this->resolveRelations);
		}

		if (config('storyblok.resolve_links')) {
			$options['resolve_links'] = config('storyblok.resolve_links');
			$options['resolve_links_level'] = config('storyblok.resolve_links_level', 0);
		}

		return $contentApi->story($slugOrUuid, $options);
	}
}
