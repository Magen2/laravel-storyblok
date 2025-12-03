<?php

namespace Riclep\Storyblok\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Riclep\Storyblok\StoryblokFacade as Storyblok;


class StoryblokController
{
    /**
     * Display a Storyblok page for the given slug.
     *
     * @param string $slug
     * @return View
     * @throws \Exception
     */
    public function show($slug = 'home'): View
    {
        if ($this->isDenylisted($slug)) {
            throw new \Riclep\Storyblok\Exceptions\DenylistedUrlException($slug);
        }

        // Use the Storyblok Page::render() flow so storyblok.pages.page is used
        return Storyblok::read($slug)->render();
    }

    /**
     * Delete the cached Storyblok API responses.
     *
     * @return void
     */
    public function destroy(): void
    {
        if (Cache::getStore() instanceof \Illuminate\Cache\TaggableStore) {
            Cache::store(config('storyblok.sb_cache_driver'))->tags('storyblok')->flush();
        } else {
            Cache::store(config('storyblok.sb_cache_driver'))->flush();
        }
    }

    /**
     * Determine if the given slug is denylisted.
     *
     * @param string $slug
     * @return bool
     */
    protected function isDenylisted(string $slug): bool
    {
        foreach (config('storyblok.denylist', []) as $pattern) {
            if ($pattern === $slug) {
                return true;
            }

            if (strlen($pattern) > 1 && $pattern[0] === '/' && substr($pattern, -1) === '/') {
                if (preg_match($pattern, $slug)) {
                    return true;
                }
            }
        }

        return false;
    }
}
