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
        // Command::argument()'s inferred type differs across larastan
        // versions (exact string read from the signature vs Symfony's full
        // union), so go through the raw input: getArgument() is mixed on
        // every version, making the narrowing legitimate everywhere.
        $nameArgument = $this->input->getArgument('name');
        $name = str_replace('\\', '/', is_string($nameArgument) ? $nameArgument : '');
        $segments = array_values(array_filter(explode('/', $name), static fn (string $part): bool => $part !== ''));

        if ($segments === []) {
            $this->components->error('A class name is required.');

            return self::FAILURE;
        }

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                $this->components->error(sprintf('[%s] is not a valid PHP class name.', $segment));

                return self::FAILURE;
            }
        }

        $class = array_pop($segments);

        $dirOption = $this->option('dir');
        $dir = trim(trim(str_replace('\\', '/', is_string($dirOption) ? $dirOption : '')), '/');

        if ($dir === '') {
            $this->components->error('A target directory is required.');

            return self::FAILURE;
        }

        foreach (explode('/', $dir) as $dirSegment) {
            if (trim($dirSegment) === '' || in_array($dirSegment, ['.', '..'], true)) {
                $this->components->error(sprintf('[%s] is not a valid --dir path segment.', $dirSegment));

                return self::FAILURE;
            }
        }

        $directory = $this->laravel->basePath(implode('/', [$dir, ...$segments]));
        $namespace = $this->deriveNamespace($dir, $segments);

        $targets = [
            [
                'path' => $directory.'/'.$class.'.php',
                'stub' => $this->option('events') ? 'action.events.stub' : 'action.stub',
                'label' => 'Action',
            ],
        ];

        if ($this->option('events')) {
            $targets[] = [
                'path' => $directory.'/'.$class.'Event.php',
                'stub' => 'action-event.stub',
                'label' => 'Enum',
            ];
        }

        // Check every target before writing any: --events must never leave
        // a half-generated action/enum pair behind.
        if (! $this->option('force')) {
            foreach ($targets as $target) {
                if ($this->files->exists($target['path'])) {
                    $this->components->error(sprintf('[%s] already exists.', $this->relativePath($target['path'])));

                    return self::FAILURE;
                }
            }
        }

        $replacements = [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $class,
            '{{ event }}' => $class.'Event',
        ];

        $this->files->ensureDirectoryExists($directory);

        foreach ($targets as $target) {
            $contents = str_replace(
                array_keys($replacements),
                array_values($replacements),
                $this->files->get($this->resolveStubPath($target['stub'])),
            );

            $this->files->put($target['path'], $contents);

            $this->components->info(sprintf('%s [%s] created successfully.', $target['label'], $this->relativePath($target['path'])));
        }

        return self::SUCCESS;
    }

    /**
     * Derive the namespace from the target directory: a directory under the
     * application path maps onto the application namespace (app/Domain ->
     * App\Domain); anything else is a best-effort studly-casing of the
     * path segments (domain/actions -> Domain\Actions). The fallback also
     * covers apps whose container is not a full Foundation application.
     * Nested name segments extend the namespace as-is.
     *
     * @param  list<string>  $segments
     */
    protected function deriveNamespace(string $dir, array $segments): string
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

        return implode('\\', [...$parts, ...$segments]);
    }

    /**
     * An application overrides a stub by publishing a same-named file to
     * its stubs/ directory (Laravel's convention) — no publish command.
     */
    protected function resolveStubPath(string $stub): string
    {
        $custom = $this->laravel->basePath('stubs/'.$stub);

        return $this->files->exists($custom) ? $custom : __DIR__.'/../../stubs/'.$stub;
    }

    protected function relativePath(string $path): string
    {
        return ltrim(Str::after($path, $this->laravel->basePath()), '/');
    }
}
