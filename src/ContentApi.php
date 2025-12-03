<?php

namespace Riclep\Storyblok;

use Storyblok\Api\StoriesApi;
use Storyblok\Api\Request\StoriesRequest;
use Storyblok\Api\Request\StoryRequest;
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
    public function __construct(private StoriesApi $storiesApi) {}

    /**
     * Fetch a single story by slug or UUID.
     * @param string $slugOrUuid
     * @param array $options [language, resolve_relations => [], resolve_links => type, resolve_links_level => int]
     * @return array Raw story response (legacy format)
     */
    public function story(string $slugOrUuid, array $options = []): array
    {
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
    }

    /**
     * Fetch multiple stories based on legacy style settings.
     * Recognised keys: page, per_page, sort_by (field:direction), filter_query, starts_with, language, resolve_relations, resolve_links.
     */
    public function stories(array $settings = []): array
    {
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
        // skip setting type/level if classes are unavailable

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
}
