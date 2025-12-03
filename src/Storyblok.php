<?php


namespace Riclep\Storyblok;


use Riclep\Storyblok\Traits\HasChildClasses;
use Storyblok\Api\StoriesApi;
use Storyblok\Api\Request\StoryRequest;
use Storyblok\Api\Domain\Value\Resolver\RelationCollection;

class Storyblok
{
	use HasChildClasses;

	/**
	 * Create a new Storyblok service instance.
	 *
	 * @param StoriesApi $storiesApi
	 */
	public function __construct(protected StoriesApi $storiesApi)
	{
	}

	/**
	 * Read a story from the Storyblok Content API.
	 *
	 * @param string      $slug
	 * @param array|null  $resolveRelations
	 * @param string|null $language
	 * @param string|null $fallbackLanguage
	 * @return Page
	 */
	public function read(string $slug, ?array $resolveRelations = null, ?string $language = null, ?string $fallbackLanguage = null): Page
	{
		$relations = $resolveRelations ? new RelationCollection($resolveRelations) : new RelationCollection();

		$lang = $language ?? 'default';

		$request = new StoryRequest(
			language: $lang,
			version: null,
			withRelations: $relations
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
	public function setData($data): mixed
	{
		$response = $data;

		$class = $this->getChildClassName('Page', $response['content']['component']);

		return new $class($response);
	}
}
