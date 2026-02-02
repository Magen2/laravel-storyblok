<?php


namespace Riclep\Storyblok;


use Riclep\Storyblok\Traits\HasChildClasses;
use Storyblok\Api\StoriesApi;
use Storyblok\Api\DatasourceEntriesApi;
use Storyblok\Api\Request\StoryRequest;
use Storyblok\Api\Domain\Value\Resolver\RelationCollection;

class Storyblok
{
	use HasChildClasses;

	/**
	 * Create a new Storyblok service instance.
	 */
	public function __construct(
		protected StoriesApi $storiesApi,
		protected ContentApi $contentApi,
		protected DatasourceEntriesApi $datasourceEntriesApi,
	) {
	}

	/**
	 * Fetch multiple stories from the Storyblok Content API.
	 *
	 * @param array $settings
	 * @return array
	 */
	public function stories(array $settings = []): array
	{
		return $this->contentApi->stories($settings);
	}

	/**
	 * Fetch a single story from the Storyblok Content API.
	 *
	 * @param string $slugOrUuid
	 * @param array $options
	 * @return array
	 */
	public function story(string $slugOrUuid, array $options = []): array
	{
		return $this->contentApi->story($slugOrUuid, $options);
	}

	/**
	 * Read a story from the Storyblok Content API.
	 *
	 * @param string $slug
	 * @param array|null $resolveRelations
	 * @param string|null $language
	 * @param string|null $fallbackLanguage
	 * @return Page
	 */
	public function read(
		string $slug,
		?array $resolveRelations = null,
		?string $language = null,
		?string $fallbackLanguage = null,
	): Page {
		$relations = $resolveRelations ? new RelationCollection($resolveRelations) : new RelationCollection();

		$lang = $language ?? 'default';

		$request = new StoryRequest(
			language: $lang,
			version: null,
			withRelations: $relations,
		);

		$response = $this->storiesApi->bySlug($slug, $request);

		$story = $response->story;


		$class = $this->getChildClassName('Page', $story['content']['component']);

		return new $class($story);
	}

	/**
	 * Create a Page instance from raw story data.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	public function setData(mixed $data): mixed
	{
		$response = $data;

		$class = $this->getChildClassName('Page', $response['content']['component']);

		return new $class($response);
	}

	/**
	 * Fetch all datasource entries.
	 *
	 * @param int $page
	 * @param int $perPage
	 * @return array
	 */
	public function datasourceEntries(int $page = 1, int $perPage = 25): array
	{
		return $this->contentApi->datasourceEntries($page, $perPage);
	}

	/**
	 * Fetch datasource entries by datasource slug.
	 *
	 * @param string $datasource
	 * @param int $page
	 * @param int $perPage
	 * @return array
	 */
	public function datasource(string $datasource, int $page = 1, int $perPage = 25): array
	{
		return $this->contentApi->datasourceEntriesByDatasource($datasource, $page, $perPage);
	}

	/**
	 * Fetch datasource entries by dimension.
	 *
	 * @param string $dimension
	 * @param int $page
	 * @param int $perPage
	 * @return array
	 */
	public function datasourceByDimension(string $dimension, int $page = 1, int $perPage = 25): array
	{
		return $this->contentApi->datasourceEntriesByDimension($dimension, $page, $perPage);
	}

	/**
	 * Fetch datasource entries by datasource and dimension.
	 *
	 * @param string $datasource
	 * @param string $dimension
	 * @param int $page
	 * @param int $perPage
	 * @return array
	 */
	public function datasourceWithDimension(
		string $datasource,
		string $dimension,
		int $page = 1,
		int $perPage = 25,
	): array {
		return $this->contentApi->datasourceEntriesByDatasourceDimension($datasource, $dimension, $page, $perPage);
	}

	/**
	 * Get datasource entries as key-value pairs (value => name).
	 *
	 * @param string $datasource
	 * @param string|null $dimension
	 * @return array<string, string>
	 */
	public function datasourceAsKeyValue(string $datasource, ?string $dimension = null): array
	{
		return $this->contentApi->datasourceAsKeyValue($datasource, $dimension);
	}

	/**
	 * Get datasource entries as name-value pairs (name => value).
	 *
	 * @param string $datasource
	 * @param string|null $dimension
	 * @return array<string, string>
	 */
	public function datasourceAsNameValue(string $datasource, ?string $dimension = null): array
	{
		return $this->contentApi->datasourceAsNameValue($datasource, $dimension);
	}

	/**
	 * Clear all Storyblok cache entries.
	 *
	 * @return void
	 */
	public function clearCache(): void
	{
		$this->contentApi->clearCache();
	}

	/**
	 * Clear cache for a specific story.
	 *
	 * @param string $slugOrUuid
	 * @return void
	 */
	public function clearStoryCache(string $slugOrUuid): void
	{
		$this->contentApi->clearStoryCache($slugOrUuid);
	}

	/**
	 * Clear cache for a specific datasource.
	 *
	 * @param string $datasource
	 * @return void
	 */
	public function clearDatasourceCache(string $datasource): void
	{
		$this->contentApi->clearDatasourceCache($datasource);
	}
}
