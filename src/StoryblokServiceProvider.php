<?php

namespace Riclep\Storyblok;

use Illuminate\Support\ServiceProvider;
use Riclep\Storyblok\Console\BlockMakeCommand;
use Riclep\Storyblok\Console\BlockSyncCommand;
use Riclep\Storyblok\Console\FolderMakeCommand;
use Riclep\Storyblok\Console\PageMakeCommand;
use Riclep\Storyblok\Console\StubViewsCommand;
use Storyblok\Api\StoryblokClient;
use Storyblok\Api\StoriesApi;

class StoryblokServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(): void
    {
		$this->loadRoutesFrom(__DIR__.'/routes/api.php');

		$this->loadViewsFrom(__DIR__.'/resources/views', 'laravel-storyblok');

        if ($this->app->runningInConsole()) {
			$this->publishes([
				__DIR__.'/../config/storyblok.php' => config_path('storyblok.php'),
				__DIR__ . '/../stubs/Page.stub' => app_path('Storyblok') . '/Page.php',
				__DIR__ . '/../stubs/Block.stub' => app_path('Storyblok') . '/Block.php',
				__DIR__ . '/../stubs/Asset.stub' => app_path('Storyblok') . '/Asset.php',
				__DIR__ . '/../stubs/Folder.stub' => app_path('Storyblok') . '/Folder.php',
			], 'storyblok');
        }

		$this->commands([
			BlockMakeCommand::class,
			BlockSyncCommand::class,
			FolderMakeCommand::class,
			PageMakeCommand::class,
			StubViewsCommand::class
		]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/storyblok.php', 'storyblok');

        $token = config('storyblok.draft') ? config('storyblok.api_preview_key') : config('storyblok.api_public_key');
        $rawBase = (string) config('storyblok.delivery_api_base_url');
        $baseUri = rtrim($rawBase, '/');
        if (!str_starts_with($baseUri, 'http://') && !str_starts_with($baseUri, 'https://')) {
            $baseUri = (config('storyblok.use_ssl') ? 'https://' : 'http://') . $baseUri;
        }

        $client = new StoryblokClient($baseUri, (string) $token);

        // Configure cache on the official client in published + cache mode
        if (!config('storyblok.draft') && config('storyblok.cache')) {
            $storage = new \Storyblok\Api\CacheVersion\InMemoryStorage();
            $client = $client->withCacheVersionStorage($storage);
        }

        $this->app->instance(StoryblokClient::class, $client);

        $version = config('storyblok.draft') ? 'draft' : 'published';
        $storiesApi = new StoriesApi($client, $version);
        $this->app->instance(StoriesApi::class, $storiesApi);

        $this->app->singleton(\Riclep\Storyblok\ContentApi::class, function () use ($storiesApi) {
            return new \Riclep\Storyblok\ContentApi($storiesApi);
        });

        $this->app->singleton('storyblok', function () use ($storiesApi) {
            return new Storyblok($storiesApi);
        });

        // Image transformer driver singleton (matching previous behaviour)
        $this->app->singleton('image-transformer.driver', function ($app) {
            return $app['image-transformer']->driver();
        });
    }
}
