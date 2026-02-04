@if (config('storyblok.edit_mode'))
    <script src="//app.storyblok.com/f/storyblok-v2-latest.js" type="text/javascript" @cspNonce></script>
    <script type="text/javascript" @cspNonce>
        (function() {
            var csrfToken = document.querySelector('meta[name="csrf-token"]');
            var csrfTokenValue = csrfToken ? csrfToken.content : '';
            var previewTimeout = null;
            var isRendering = false;
            var abortController = null;

            // Initialize StoryblokBridge with proper configuration
            var storyblokInstance = new StoryblokBridge({
                resolveRelations: @js(config('storyblok.resolve_relations', [])),
                preventClicks: true
            });

            // Handle save/publish events - reload page to get fresh content
            storyblokInstance.on(['change', 'published'], function() {
                window.location.reload();
            });

            // Handle enter event - triggered when story is loaded in editor
            storyblokInstance.on('enterEditmode', function(event) {
                console.log('[Storyblok] Entered edit mode');
            });

            @if (config('storyblok.live_preview'))
            // Handle input event - triggered on every change in the editor
            storyblokInstance.on('input', function(event) {
                console.log('[Storyblok] Input event received', event);
                if (!event || !event.story) {
                    console.log('[Storyblok] No story in event');
                    return;
                }

                if (previewTimeout) {
                    clearTimeout(previewTimeout);
                }
                previewTimeout = setTimeout(function() {
                    renderLivePreview(event.story);
                }, 150);
            });

            function renderLivePreview(story) {
                console.log('[Storyblok] Rendering live preview for story:', story.name || story.slug);
                if (isRendering) {
                    console.log('[Storyblok] Already rendering, skipping');
                    return;
                }
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
                    console.log('[Storyblok] Preview response status:', response.status);
                    if (!response.ok) {
                        return response.json().then(function(err) {
                            console.error('[Storyblok] Live preview error:', err);
                            return null;
                        });
                    }
                    return response.json();
                })
                .then(function(data) {
                    console.log('[Storyblok] Preview data received:', data);
                    if (data && data.success && data.blocks) {
                        updateBlocksInDom(data.blocks);
                    } else if (data && data.error) {
                        console.error('[Storyblok] Server error:', data.error);
                    }
                })
                .catch(function(error) {
                    if (error.name !== 'AbortError') {
                        console.error('[Storyblok] Live preview error:', error);
                    }
                })
                .finally(function() {
                    isRendering = false;
                });
            }

            function updateBlocksInDom(blocks) {
                console.log('[Storyblok] Updating DOM with', blocks.length, 'blocks');
                var container = document.querySelector(@js(config('storyblok.live_element', '.page-container')));
                if (!container) {
                    console.error('[Storyblok] Container not found:', @js(config('storyblok.live_element', '.page-container')));
                    return;
                }

                var newHtml = blocks.map(function(b) { return b.html; }).join('\n');

                // Use a template element to parse HTML properly, preserving whitespace
                var template = document.createElement('template');
                template.innerHTML = newHtml;

                // Clear container and append parsed content
                container.innerHTML = '';
                while (template.content.firstChild) {
                    container.appendChild(template.content.firstChild);
                }

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
