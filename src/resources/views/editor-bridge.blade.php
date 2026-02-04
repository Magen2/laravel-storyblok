@if (config('storyblok.edit_mode'))
    <script src="//app.storyblok.com/f/storyblok-v2-latest.js" type="text/javascript" @cspNonce></script>
    <script type="text/javascript" @cspNonce>
        (function() {
            var csrfToken = document.querySelector('meta[name="csrf-token"]');
            var csrfTokenValue = csrfToken ? csrfToken.content : '';
            var previewTimeout = null;
            var isRendering = false;
            var abortController = null;

            var storyblokInstance = new StoryblokBridge({
                accessToken: @js(config('storyblok.api_preview_key'))
            });

            storyblokInstance.on(['change', 'published'], function() {
                window.location.reload();
            });

            @if (config('storyblok.live_preview'))
            storyblokInstance.on('input', function(event) {
                if (!event.story) return;

                if (previewTimeout) {
                    clearTimeout(previewTimeout);
                }
                previewTimeout = setTimeout(function() {
                    renderLivePreview(event.story);
                }, 150);
            });

            function renderLivePreview(story) {
                if (isRendering) return;
                isRendering = true;

                if (abortController) {
                    abortController.abort();
                }
                abortController = new AbortController();

                fetch(@js(route('storyblok.preview.render')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfTokenValue,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ story: story }),
                    signal: abortController.signal
                })
                .then(function(response) {
                    if (!response.ok) {
                        console.error('Live preview render failed:', response.status);
                        return null;
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data && data.success && data.blocks) {
                        updateBlocksInDom(data.blocks);
                    }
                })
                .catch(function(error) {
                    if (error.name !== 'AbortError') {
                        console.error('Live preview error:', error);
                    }
                })
                .finally(function() {
                    isRendering = false;
                });
            }

            function updateBlocksInDom(blocks) {
                var container = document.querySelector(@js(config('storyblok.live_element', '.page-container')));
                if (!container) return;

                var newHtml = blocks.map(function(b) { return b.html; }).join('');
                container.innerHTML = newHtml;

                if (window.Alpine) {
                    container.querySelectorAll('[x-data]').forEach(function(el) {
                        if (!el._x_dataStack) {
                            window.Alpine.initTree(el);
                        }
                    });
                }

                @if (config('storyblok.live_links'))
                document.dispatchEvent(new Event('DOMContentLoaded'));
                @endif
            }
            @endif

            @if (config('storyblok.live_links'))
            function appendQueryParamsToPath(path) {
                try {
                    var link = new URL(path, window.location.origin);
                    if (link.origin !== window.location.origin) {
                        return path;
                    }
                } catch (e) {
                    return path;
                }

                var currentUrl = window.location.href;
                var queryParams = currentUrl.split('?')[1];

                if (queryParams) {
                    path += (path.includes('?') ? '&' : '?') + queryParams;
                }

                return path;
            }

            function updateAllLinks() {
                var urlParams = new URLSearchParams(window.location.search);
                var hasStoryblokParams = false;

                for (var pair of urlParams.entries()) {
                    if (pair[0].startsWith('_storyblok')) {
                        hasStoryblokParams = true;
                        break;
                    }
                }

                if (hasStoryblokParams) {
                    var links = document.querySelectorAll('a[href]');
                    links.forEach(function(link) {
                        var originalHref = link.getAttribute('href');
                        var updatedHref = appendQueryParamsToPath(originalHref);
                        link.setAttribute('href', updatedHref);
                    });
                }
            }

            document.addEventListener('DOMContentLoaded', updateAllLinks);
            @endif
        })();
    </script>
@endif
