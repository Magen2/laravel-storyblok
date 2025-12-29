<?php

namespace Riclep\Storyblok\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Riclep\Storyblok\Traits\HasChildClasses;

/**
 * Generate stub Blade views for Storyblok components.
 */
class StubViewsCommand extends Command
{
    use HasChildClasses;

    /**
     * @var string The name and signature of the console command
     */
    protected $signature = 'ls:stub-views {--O|overwrite}';

    /**
     * @var string The console command description
     */
    protected $description = 'Generate stub Blade views for Storyblok components.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->makeDirectories();

        $response = Http::withHeader('Authorization', config('storyblok.oauth_token'))
            ->withOptions([
                'base_uri' => (config('storyblok.use_ssl') ? 'https://' : 'http://') . rtrim((string) config('storyblok.management_api_base_url'), '/'),
            ])
            ->get('v1/spaces/' . config('storyblok.space_id') . '/components/');

        if ($response->failed()) {
            $this->error('Failed to fetch components from Storyblok Management API: ' . $response->body());
            return;
        }

        $components = collect($response->json('components') ?? []);

        $components->each(function ($component) {
            $path = resource_path('views/' . str_replace('.', '/', config('storyblok.view_path')) . 'blocks/');
            $filename =  $component['name'] . '.blade.php';

            if ($this->option('overwrite') || !file_exists($path . $filename)) {
                $content = file_get_contents(__DIR__ . '/stubs/blade.stub');
                $content = str_replace([
                    '#NAME#',
                    '#CLASS#'
                ], [
                    $component['name'],
                    $this->getChildClassName('Block', $component['name'])
                ], $content);

                $body = '';

                foreach ($component['schema'] as $name => $field) {
                    $body = $this->writeBlade($field, $name, $body);
                }

                $content = str_replace('#BODY#', $body, $content);

                file_put_contents($path . $filename, $content);

                $this->info('Created View: '. $component['name'] . '.blade.php');
            }
        });

        if ($this->option('overwrite') || !file_exists(resource_path('views/storyblok/pages') . '/page.blade.php')) {
            File::copy(__DIR__ . '/stubs/page.blade.stub', resource_path('views/storyblok/pages') . '/page.blade.php');

            $this->info('Created Page: page.blade.php');

            $this->info('Files created in your views' . DIRECTORY_SEPARATOR . 'storyblok folder.');
        }
    }

    /**
     * Ensure the Storyblok view directories exist.
     */
    protected function makeDirectories(): void
    {
        if (!file_exists(resource_path('views/' . rtrim(config('storyblok.view_path'), '.')))) {
            File::makeDirectory(resource_path('views/' . rtrim(config('storyblok.view_path'), '.')));
        }

        if (!file_exists(resource_path('views/' . rtrim(config('storyblok.view_path'), '.') . '/blocks'))) {
            File::makeDirectory(resource_path('views/' . rtrim(config('storyblok.view_path'), '.') . '/blocks'));
        }

        if (!file_exists(resource_path('views/' . rtrim(config('storyblok.view_path'), '.') . '/pages'))) {
            File::makeDirectory(resource_path('views/' . rtrim(config('storyblok.view_path'), '.') . '/pages'));
        }
    }

    /**
     * Build the Blade template body for a single Storyblok field.
     */
    protected function writeBlade(array $field, int|string $name, string $body): string
    {
        if (str_starts_with($name, 'tab-')) {
            return $body;
        }

        switch ($field['type']) {
            case 'options':
            case 'bloks':
                $body .= "    @if (\$block->{$name})\n";
                $body .= "        @foreach (\$block->{$name} as \$childBlock)\n";
                $body .= "            {{ \$childBlock->render() }}\n";
                $body .= "        @endforeach\n";
                $body .= "    @endif\n";
                break;

            case 'datetime':
                $body .= "    <time datetime=\"{{ \$block->{$name}->content()->toIso8601String() }}\">{{ \$block->{$name} }}</time>\n";
                break;

            case 'number':
            case 'text':
                $body .= "    <p>{{ \$block->{$name} }}</p>\n";
                break;

            case 'multilink':
                $body .= "    <a href=\"{{ \$block->{$name}->cached_url }}\"></a>\n";
                break;

            case 'textarea':
            case 'richtext':
                $body .= "    <div>{!! \$block->{$name} !!}</div>\n";
                break;

            case 'asset':
                if (array_key_exists('filetypes', $field) && in_array('images', $field['filetypes'], true)) {
                    $body .= "    @if (\$block->{$name}->hasFile())\n";
                    $body .= "        <img src=\"{{ \$block->{$name}->transform()->resize(100, 100) }}\" width=\"{{ \$block->{$name}->width() }}\" height=\"{{ \$block->{$name}->height() }}\" alt=\"{{ \$block->{$name}->alt() }}\">\n";
                    $body .= "    @endif\n";
                } else {
                    $body .= "    <a href=\"{{ \$block->{$name} }}\">Download</a>\n";
                }
                break;

            case 'image':
                $body .= "    @if (\$block->{$name}->hasFile())\n";
                $body .= "        <img src=\"{{ \$block->{$name}->transform()->resize(100, 100)->format('webp', 60) }}\" width=\"{{ \$block->{$name}->width() }}\" height=\"{{ \$block->{$name}->height() }}\" alt=\"{{ \$block->{$name}->alt() }}\">\n";
                $body .= "    @endif\n";
                break;

            case 'file':
                $body .= "    @if (\$block->{$name}->hasFile())\n";
                $body .= "        <a href=\"{{ \$block->{$name} }}\">{{ \$block->{$name}->filename }}</a>\n";
                $body .= "    @endif\n";
                break;

            default:
                $body .= "    {{ \$block->{$name} }}\n";
        }

        return $body;
    }
}
