<?php

declare(strict_types=1);

// One rule per function: Pest misses some when given the list at once.
foreach (['dd', 'dump', 'ray', 'var_dump', 'print_r', 'var_export', 'die'] as $function) {
    arch("no {$function}() calls are left behind")
        ->expect($function)
        ->not->toBeUsed();
}

arch('every package file declares strict types')
    ->expect('Phoenix1331\Clorian')
    ->toUseStrictTypes();

/**
 * Every PHP file under the given directories, for checks Pest's arch
 * expectations cannot make: Pest test files have no namespace, and calls to
 * a function that is not installed (ray) resolve into the caller's namespace.
 *
 * @return array<string, string> path => contents
 */
function phpSources(string ...$directories): array
{
    $root = dirname(__DIR__, 2);
    $sources = [];

    foreach ($directories as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
    }

    return $sources;
}

it('declares strict types in every source, test, config and database file', function (): void {
    $missing = array_keys(array_filter(
        phpSources('src', 'tests', 'config', 'database'),
        static fn (string $source): bool => ! str_contains($source, 'declare(strict_types=1);'),
    ));

    expect($missing)->toBe([]);
});

it('leaves no ray() calls behind', function (): void {
    $calls = array_keys(array_filter(
        phpSources('src', 'config', 'database'),
        static fn (string $source): bool => preg_match('/(?<![\w$>:\\\\])ray\s*\(/', $source) === 1,
    ));

    expect($calls)->toBe([]);
});

arch('php best practices')->preset()->php();

arch('no insecure functions')->preset()->security();

/*
 * The decision core is plain PHP so a Symfony bundle can reuse it later.
 * Laravel stays at the edges: provider, policies, models, observer, jobs,
 * commands and the database-backed threshold lookup (Tuning).
 *
 * One rule per namespace: Pest misses violations when expect() is given
 * several namespaces together with not->toUse().
 */
$core = [
    'Phoenix1331\Clorian\Questions',
    'Phoenix1331\Clorian\Resolution',
    'Phoenix1331\Clorian\Calibration',
    'Phoenix1331\Clorian\Reviews',
    'Phoenix1331\Clorian\Exceptions',
    'Phoenix1331\Clorian\Drivers',
];

foreach ($core as $namespace) {
    arch("{$namespace} does not depend on Laravel")
        ->expect($namespace)
        ->not->toUse(['Illuminate', 'Laravel', 'Orchestra', 'Carbon'])
        // HTTP lives in one class, through Laravel's client so Http::fake() works.
        ->ignoring('Phoenix1331\Clorian\Drivers\JevDriver');

    arch("{$namespace} never reaches into the Laravel edges of the package")
        ->expect($namespace)
        ->not->toUse([
            'Phoenix1331\Clorian\Models',
            'Phoenix1331\Clorian\Policies',
            'Phoenix1331\Clorian\Observers',
            'Phoenix1331\Clorian\Jobs',
            'Phoenix1331\Clorian\Commands',
            'Phoenix1331\Clorian\Facades',
            'Phoenix1331\Clorian\Tuning',
        ]);
}

arch('the package is headless: no UI, views or routes')
    ->expect('Phoenix1331\Clorian')
    ->not->toUse([
        'Filament',
        'Livewire',
        'Inertia',
        'Illuminate\View',
        'Illuminate\Routing',
        'Illuminate\Support\Facades\View',
        'Illuminate\Support\Facades\Route',
        'Illuminate\Support\Facades\Blade',
        'view',
        'route',
    ]);

it('ships no views or route files', function (): void {
    $root = dirname(__DIR__, 2);

    expect(is_dir("{$root}/resources/views"))->toBeFalse()
        ->and(is_dir("{$root}/routes"))->toBeFalse()
        ->and((string) file_get_contents("{$root}/src/ClorianServiceProvider.php"))
        ->not->toContain('loadViewsFrom')
        ->not->toContain('loadRoutesFrom');
});

arch('stored values are string-backed enums')
    ->expect([
        'Phoenix1331\Clorian\Resolution\Status',
        'Phoenix1331\Clorian\Reviews\ReviewAction',
        'Phoenix1331\Clorian\Models\WeighingStatus',
    ])
    ->toBeStringBackedEnums();

arch('models are final Eloquent models')
    ->expect('Phoenix1331\Clorian\Models')
    ->classes()
    ->toBeFinal()
    ->toExtend('Illuminate\Database\Eloquent\Model')
    ->ignoring('Phoenix1331\Clorian\Models\WeighingStatus');

arch('exceptions are final and extend a standard exception')
    ->expect('Phoenix1331\Clorian\Exceptions')
    ->toBeFinal()
    ->toExtend('Exception');
