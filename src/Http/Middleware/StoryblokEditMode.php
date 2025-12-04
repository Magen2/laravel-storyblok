<?php

namespace Riclep\Storyblok\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class StoryblokEditMode
{
    /**
     * Handle an incoming request and set Storyblok edit mode when appropriate.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $storyblokRequest = $request->get('_storyblok_tk');

        if (!empty($storyblokRequest)) {
            $preToken = $storyblokRequest['space_id'] . ':' . config('storyblok.api_preview_key') . ':' . $storyblokRequest['timestamp'];
            $token = sha1($preToken);

            if ($token === $storyblokRequest['token'] && (int) $storyblokRequest['timestamp'] > strtotime('now') - 3600) {
                config(['storyblok.edit_mode' => true]);
                config(['storyblok.draft' => true]);
            }
        }

        return $next($request);
    }
}

