<?php

namespace Riclep\Storyblok;

use Illuminate\Support\Facades\Cache;
use Storyblok\Api\StoriesApi;
use Storyblok\Api\DatasourceEntriesApi;
use Storyblok\Api\Request\StoriesRequest;
use Storyblok\Api\Request\StoryRequest;
use Storyblok\Api\Request\DatasourceEntriesRequest;
use Storyblok\Api\Domain\Value\Dto\Pagination;
use Storyblok\Api\Domain\Value\Dto\SortBy;
use Storyblok\Api\Domain\Value\Dto\Direction;
use Storyblok\Api\Domain\Value\Filter\FilterCollection;
use Storyblok\Api\Domain\Value\Filter\Filters\InFilter;
use Storyblok\Api\Domain\Value\Filter\Filters\AnyInArrayFilter;
use Storyblok\Api\Domain\Value\Filter\Filters\LikeFilter;
use Storyblok\Api\Domain\Value\Filter\Filters\NotLikeFilter;
use Storyblok\Api\Domain\Value\Filter\Filters\IsFilter;
use Storyblok\Api\Domain\Value\Filter\Filters\NotInFilter;
use Storyblok\Api\Domain\Value\Filter\Filters\AllInArrayFilter;
use Storyblok\Api\Domain\Value\Resolver\RelationCollection;
use Storyblok\Api\Domain\Value\Resolver\ResolveLinks;
use Storyblok\Api\Domain\Value\Slug\Slug;
use Storyblok\Api\Domain\Value\Uuid;

/**
 * Central wrapper translating legacy package style arrays into Storyblok SDK requests.
 */
class ContentApi
{
    /** @var string Cache key prefix. */
    private const CACHE_PREFIX = 'storyblok:';

    /**
     * Create a new ContentApi instance.
     */
    public function __construct(
        private StoriesApi $storiesApi,
        private DatasourceEntriesApi $datasourceEntriesApi,
    ) {
    }

    /**
     * Check if caching is enabled (checks config dynamically).
     */
    private function isCacheEnabled(): bool
    {
        return !config('storyblok.draft') && config('storyblok.cache', true);
    }

    /**
     * Get cache duration in minutes.
     */
    private function getCacheDuration(): int
    {
        return (int) config('storyblok.cache_duration', 60);
    }

    /**
     * Generate a cache key from the given parameters.
     */
    private function cacheKey(string $type, mixed ...$params): string
    {
        return self::CACHE_PREFIX . $type . ':' . md5(serialize($params));
    }

    /**
     * Retrieve from cache or execute callback and store result.
     */
    private function remember(string $key, callable $callback): mixed
    {
        if (!$this->isCacheEnabled()) {
            return $callback();
        }

        return Cache::remember($key, now()->addMinutes($this->getCacheDuration()), $callback);
    }

    /**
     * Clear all Storyblok cache entries.
     */
    public function clearCache(): void
    {
        Cache::flush(); // Note: This clears all cache; for tag-based clearing, use cache tags
    }

    /**
     * Clear cache for a specific story.
     */
    public function clearStoryCache(string $slugOrUuid): void
    {
        $key = $this->cacheKey('story', $slugOrUuid);
        Cache::forget($key);
    }

    /**
     * Clear cache for a specific datasource.
     */
    public function clearDatasourceCache(string $datasource): void
    {
        // Clear with various pagination combinations isn't practical
        // This is a best-effort approach
        for ($page = 1; $page <= 10; $page++) {
            foreach ([25, 100, 1000] as $perPage) {
                Cache::forget($this->cacheKey('datasource', $datasource, $page, $perPage));
            }
        }
    }

    /**
     * Fetch a single story by slug or UUID.
     *
     * @param string $slugOrUuid
     * @param array $options
     * @return array
     */
    public function story(string $slugOrUuid, array $options = []): array
    {
        $cacheKey = $this->cacheKey('story', $slugOrUuid, $options);

        return $this->remember($cacheKey, function () use ($slugOrUuid, $options) {
            $language = $options['language'] ?? 'default';

            $relations = new RelationCollection($options['resolve_relations'] ?? []);

            $resolveLinks = new ResolveLinks();
            $request = new StoryRequest(
                language: $language,
                withRelations: $relations,
                resolveLinks: $resolveLinks,
            );

            if ($this->isUuid($slugOrUuid)) {
                $resp = $this->storiesApi->byUuid(new Uuid($slugOrUuid), $request);
            } else {
                $resp = $this->storiesApi->bySlug($slugOrUuid, $request);
            }

            return [
                'story' => $resp->story,
                'cv' => $resp->cv,
                'rels' => $resp->rels,
                'rel_uuids' => $resp->relUuids,
                'links' => $resp->links,
            ];
        });
    }

    /**
     * Fetch multiple stories based on settings.
     *
     * @param array $settings
     * @return array
     */
    public function stories(array $settings = []): array
    {
        $cacheKey = $this->cacheKey('stories', $settings);

        return $this->remember($cacheKey, function () use ($settings) {
            $page = (int) ($settings['page'] ?? 1);
            $perPage = (int) ($settings['per_page'] ?? 25);
            $pagination = new Pagination(page: max(1, $page), perPage: $perPage);

            $sortBy = null;
            if (!empty($settings['sort_by'])) {
                [$field, $direction] = array_pad(explode(':', $settings['sort_by']), 2, 'desc');
                $dirEnum = strtolower($direction) === 'asc' ? Direction::Asc : Direction::Desc;
                $sortBy = new SortBy(field: $field, direction: $dirEnum);
            }

            $filters = $this->buildFilters($settings['filter_query'] ?? []);

            $startsWith = null;
            if (!empty($settings['starts_with'])) {
                $startsWith = new Slug($settings['starts_with']);
            }

            $relations = new RelationCollection($settings['resolve_relations'] ?? []);

            $resolveLinks = new ResolveLinks();

            $request = new StoriesRequest(
                language: $settings['language'] ?? 'default',
                pagination: $pagination,
                sortBy: $sortBy,
                filters: $filters,
                withRelations: $relations,
                resolveLinks: $resolveLinks,
                startsWith: $startsWith,
            );

            $resp = $this->storiesApi->all($request);

            return [
                'headers' => ['Total' => [$resp->total->value], 'total' => [$resp->total->value]],
                'stories' => $resp->stories,
                'rels' => $resp->rels,
                'rel_uuids' => $resp->relUuids,
                'links' => $resp->links,
            ];
        });
    }

    /** Build FilterCollection from legacy filter_query array */
    private function buildFilters(array $legacyFilters): FilterCollection
    {
        $filters = new FilterCollection();
        foreach ($legacyFilters as $field => $ops) {
            if (!is_array($ops)) { continue; }
            foreach ($ops as $op => $value) {
                if ($value === null || $value === '') { continue; }
                $values = is_array($value) ? $value : explode(',', (string)$value);
                try {
                    $filter = match ($op) {
                        'in' => new InFilter($field, $values),
                        'any_in_array' => new AnyInArrayFilter($field, $values),
                        'like' => new LikeFilter($field, $values[0]),
                        'not_like' => new NotLikeFilter($field, $values[0]),
                        'is' => new IsFilter($field, $values[0]),
                        'not_in' => new NotInFilter($field, $values),
                        'all_in_array' => new AllInArrayFilter($field, $values),
                        default => null,
                    };
                    if ($filter) { $filters->add($filter); }
                } catch (\Throwable $e) {
                    // swallow invalid filter to mimic legacy permissive behaviour
                }
            }
        }
        return $filters;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F-]{36}$/', $value) === 1;
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
        $cacheKey = $this->cacheKey('datasource_entries', $page, $perPage);

        return $this->remember($cacheKey, function () use ($page, $perPage) {
            $pagination = new Pagination(page: $page, perPage: $perPage);
            $request = new DatasourceEntriesRequest(pagination: $pagination);
            $response = $this->datasourceEntriesApi->all($request);

            return $this->formatDatasourceResponse($response);
        });
    }

    /**
     * Fetch datasource entries by datasource slug.
     *
     * @param string $datasource
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function datasourceEntriesByDatasource(string $datasource, int $page = 1, int $perPage = 25): array
    {
        $cacheKey = $this->cacheKey('datasource', $datasource, $page, $perPage);

        return $this->remember($cacheKey, function () use ($datasource, $page, $perPage) {
            $pagination = new Pagination(page: $page, perPage: $perPage);
            $request = new DatasourceEntriesRequest(pagination: $pagination);
            $response = $this->datasourceEntriesApi->allByDatasource($datasource, $request);

            return $this->formatDatasourceResponse($response);
        });
    }

    /**
     * Fetch datasource entries by dimension.
     *
     * @param string $dimension
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function datasourceEntriesByDimension(string $dimension, int $page = 1, int $perPage = 25): array
    {
        $cacheKey = $this->cacheKey('datasource_dimension', $dimension, $page, $perPage);

        return $this->remember($cacheKey, function () use ($dimension, $page, $perPage) {
            $pagination = new Pagination(page: $page, perPage: $perPage);
            $request = new DatasourceEntriesRequest(pagination: $pagination);
            $response = $this->datasourceEntriesApi->allByDimension($dimension, $request);

            return $this->formatDatasourceResponse($response);
        });
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
    public function datasourceEntriesByDatasourceDimension(
        string $datasource,
        string $dimension,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $cacheKey = $this->cacheKey('datasource_with_dimension', $datasource, $dimension, $page, $perPage);

        return $this->remember($cacheKey, function () use ($datasource, $dimension, $page, $perPage) {
            $pagination = new Pagination(page: $page, perPage: $perPage);
            $request = new DatasourceEntriesRequest(pagination: $pagination);
            $response = $this->datasourceEntriesApi->allByDatasourceDimension($datasource, $dimension, $request);

            return $this->formatDatasourceResponse($response);
        });
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
        $entries = $dimension
            ? $this->datasourceEntriesByDatasourceDimension($datasource, $dimension, 1, 1000)
            : $this->datasourceEntriesByDatasource($datasource, 1, 1000);

        $result = [];
        foreach ($entries['entries'] as $entry) {
            $result[$entry['value']] = $entry['name'];
        }

        return $result;
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
        $entries = $dimension
            ? $this->datasourceEntriesByDatasourceDimension($datasource, $dimension, 1, 1000)
            : $this->datasourceEntriesByDatasource($datasource, 1, 1000);

        $result = [];
        foreach ($entries['entries'] as $entry) {
            $result[$entry['name']] = $entry['value'];
        }

        return $result;
    }

    /**
     * Format datasource response to array.
     *
     * @param \Storyblok\Api\Response\DatasourceEntriesResponse $response
     * @return array
     */
    private function formatDatasourceResponse(\Storyblok\Api\Response\DatasourceEntriesResponse $response): array
    {
        $entries = [];
        foreach ($response->datasourceEntries as $entry) {
            $entries[] = [
                'id' => $entry->id->value,
                'name' => $entry->name,
                'value' => $entry->value,
                'dimension_value' => $entry->dimensionValue,
            ];
        }

        return [
            'total' => $response->total->value,
            'page' => $response->pagination->page,
            'per_page' => $response->pagination->perPage,
            'entries' => $entries,
        ];
    }
}
