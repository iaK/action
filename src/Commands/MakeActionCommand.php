<?php

namespace Iak\Action\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

class MakeActionCommand extends Command
{
    protected $signature = 'make:action
        {name : The action class name, optionally nested (Orders/ShipOrder)}
        {--events : Also generate a companion event enum wired through the EmitsEvents attribute}
        {--dir=app/Actions : The directory actions are generated in, relative to the project root}
        {--force : Overwrite existing files}';

    protected $description = 'Create a new action class';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $class = $this->argument('name');
        $dirOption = $this->option('dir');
        if (! is_string($dirOption)) {
            $dirOption = 'app/Actions';
        }
        $dir = trim(str_replace('\\', '/', $dirOption), '/');

        $directory = $this->laravel->basePath($dir);
        $namespace = $this->deriveNamespace($dir);

        $path = $directory.'/'.$class.'.php';

        $contents = str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $class],
            $this->files->get($this->resolveStubPath('action.stub')),
        );

        $this->files->ensureDirectoryExists($directory);
        $this->files->put($path, $contents);

        $this->components->info(sprintf('Action [%s] created successfully.', $this->relativePath($path)));

        return self::SUCCESS;
    }

    /**
     * Derive the namespace from the target directory: a directory under the
     * application path maps onto the application namespace (app/Domain ->
     * App\Domain); anything else is a best-effort studly-casing of the
     * path segments (domain/actions -> Domain\Actions). The fallback also
     * covers apps whose container is not a full Foundation application.
     */
    protected function deriveNamespace(string $dir): string
    {
        $app = $this->laravel;
        $target = $app->basePath($dir);

        if ($app instanceof Application
            && ($target === $app->path() || str_starts_with($target, $app->path().'/'))) {
            $relative = trim((string) substr($target, strlen($app->path())), '/');

            $parts = [
                trim($app->getNamespace(), '\\'),
                ...($relative === '' ? [] : explode('/', $relative)),
            ];
        } else {
            $parts = array_map(
                static fn (string $part): string => Str::studly($part),
                explode('/', $dir),
            );
        }

        return implode('\\', $parts);
    }

    protected function resolveStubPath(string $stub): string
    {
        return __DIR__.'/../../stubs/'.$stub;
    }

    protected function relativePath(string $path): string
    {
        return ltrim(Str::after($path, $this->laravel->basePath()), '/');
    }
}
