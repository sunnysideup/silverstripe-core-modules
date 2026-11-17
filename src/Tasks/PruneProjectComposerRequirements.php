<?php

namespace Sunnysideup\CoreModules\Tasks;

use RuntimeException;
use SilverStripe\Control\Director;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Removes requirements from the project composer.json that are already
 * installed anyway because another installed package requires them.
 *
 * - `require` is pruned using the non-dev dependency graph only
 *   (so a package that is only required by a dev package stays in `require`).
 * - `require-dev` is pruned using the full dependency graph
 *   (and anything already in `require` is removed from `require-dev`).
 *
 * A requirement is only removed if it is still reachable from the remaining
 * requirements, so circular requirements can not remove each other.
 *
 * The dependency graph is read from vendor/composer/installed.json.
 * In dev mode this runs automatically on flush (see `run_on_flush`).
 */
class PruneProjectComposerRequirements extends BuildTask implements Flushable
{
    protected string $title = 'Prune Project Composer Requirements';

    protected static string $description = 'Removes all requirements (and dev requirements) from the project composer.json that are also required by installed vendor packages.';

    protected static string $commandName = 'prune-project-composer-requirements';

    /**
     * Run automatically on flush (dev mode only).
     *
     * @config
     * @var bool
     */
    private static $run_on_flush = true;

    /**
     * List of packages to skip when pruning requirements.
     * These packages will not be removed from the base composer.json even if they are required by vendor packages.
     *
     * @config
     * @var array
     */
    private static $packages_to_skip = [
        'silverstripe/recipe-cms',
        'silverstripe/recipe-core',
        'silverstripe/vendor-plugin',
        'silverstripe/mimevalidator',
        'silverstripe/recipe-plugin',
        'sunnysideup/core-modules',
        'php',
    ];

    public static function flush()
    {
        if (! static::config()->get('run_on_flush')) {
            return;
        }
        if (! Director::isDev()) {
            return;
        }
        try {
            $messages = static::create()->pruneComposerJson(Director::baseFolder());
            foreach ($messages as $message) {
                // only log changes, not "nothing to do".
                if (strpos($message, 'Removing ') === 0) {
                    Injector::inst()->get(LoggerInterface::class)->info('PruneProjectComposerRequirements: ' . $message);
                }
            }
        } catch (RuntimeException $runtimeException) {
            // never break a flush because of this
        }
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if (! Director::is_cli()) {
            $output->writeln('This task can only be run on the command line.');

            return Command::FAILURE;
        }

        if (Director::isLive()) {
            $output->writeln('This task can not be run on a live site.');

            return Command::FAILURE;
        }

        try {
            foreach ($this->pruneComposerJson(Director::baseFolder()) as $message) {
                $output->writeln($message);
            }
        } catch (RuntimeException $runtimeException) {
            $output->writeln('Error: ' . $runtimeException->getMessage());
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string> log messages
     */
    public function pruneComposerJson(string $basePath): array
    {
        $messages = [];
        $baseComposerPath = rtrim($basePath, '/') . '/composer.json';
        if (! file_exists($baseComposerPath)) {
            throw new RuntimeException('Base composer.json not found at: ' . $baseComposerPath);
        }

        $baseComposer = json_decode(file_get_contents($baseComposerPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in base composer.json.');
        }

        $require = $baseComposer['require'] ?? [];
        $requireDev = $baseComposer['require-dev'] ?? [];
        $graph = $this->getDependencyGraph($basePath);
        $skip = $this->config()->get('packages_to_skip');

        // 1. require: only reachable through non-dev packages
        foreach (array_keys($require) as $package) {
            if ($this->isSkipped($package, $skip)) {
                continue;
            }
            $remaining = $require;
            unset($remaining[$package]);
            $by = $this->findRequiringPackage($package, array_keys($remaining), $graph['nonDev']);
            if ($by !== null) {
                $messages[] = 'Removing ' . $package . ' from require as it is also required by ' . $by;
                $require = $remaining;
            }
        }

        // 2. require-dev: reachable through anything (including require)
        foreach (array_keys($requireDev) as $package) {
            if (isset($require[$package])) {
                $messages[] = 'Removing ' . $package . ' from require-dev as it is also in require';
                unset($requireDev[$package]);
                continue;
            }
            if ($this->isSkipped($package, $skip)) {
                continue;
            }
            $remaining = $requireDev;
            unset($remaining[$package]);
            $roots = array_merge(array_keys($require), array_keys($remaining));
            $by = $this->findRequiringPackage($package, $roots, $graph['all']);
            if ($by !== null) {
                $messages[] = 'Removing ' . $package . ' from require-dev as it is also required by ' . $by;
                $requireDev = $remaining;
            }
        }

        if ($messages === []) {
            $messages[] = 'Nothing to remove from ' . $baseComposerPath;
            return $messages;
        }

        $baseComposer['require'] = $require;
        if ($requireDev === []) {
            unset($baseComposer['require-dev']);
        } else {
            $baseComposer['require-dev'] = $requireDev;
        }

        $jsonData = json_encode($baseComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonData === false) {
            throw new RuntimeException('Failed to encode JSON for base composer.json: ' . json_last_error_msg());
        }
        if (! is_writable($baseComposerPath)) {
            throw new RuntimeException('Can not write to ' . $baseComposerPath);
        }

        // keep a copy of the original, so that a bad run can be undone.
        $backupPath = $baseComposerPath . '.bak';
        if (! copy($baseComposerPath, $backupPath)) {
            throw new RuntimeException('Could not make a backup copy of ' . $baseComposerPath . ' so not changing it.');
        }

        file_put_contents($baseComposerPath, $jsonData . "\n");
        $messages[] = 'Updated ' . $baseComposerPath;

        return $messages;
    }

    protected function isSkipped(string $package, array $skip): bool
    {
        return in_array($package, $skip, true)
            || $package === 'php'
            || strpos($package, 'ext-') === 0
            || strpos($package, 'lib-') === 0;
    }

    /**
     * Returns the name of the (installed) package through which $package is
     * still reachable starting from $roots, or null if it is not reachable.
     * Roots themselves do not count (we are looking for a package that requires it).
     */
    protected function findRequiringPackage(string $package, array $roots, array $graph): ?string
    {
        $seen = [];
        $queue = [];
        foreach ($roots as $root) {
            if ($root === $package) {
                continue;
            }
            $queue[] = $root;
            $seen[$root] = true;
        }
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($graph[$current] ?? [] as $dependency) {
                if ($dependency === $package) {
                    return $current;
                }
                if (! isset($seen[$dependency])) {
                    $seen[$dependency] = true;
                    $queue[] = $dependency;
                }
            }
        }

        return null;
    }

    /**
     * @return array{all: array<string, array<string>>, nonDev: array<string, array<string>>}
     */
    protected function getDependencyGraph(string $basePath): array
    {
        $path = rtrim($basePath, '/') . '/vendor/composer/installed.json';
        if (! file_exists($path)) {
            throw new RuntimeException('Could not find ' . $path . ' - run composer install first.');
        }
        $installed = json_decode(file_get_contents($path), true);
        if (! is_array($installed)) {
            throw new RuntimeException('Invalid JSON in ' . $path);
        }
        $packages = $installed['packages'] ?? $installed;
        $devNames = array_flip($installed['dev-package-names'] ?? []);

        $all = [];
        $nonDev = [];
        foreach ($packages as $package) {
            $name = $package['name'] ?? null;
            if (! $name) {
                continue;
            }
            $requires = array_keys($package['require'] ?? []);
            $all[$name] = $requires;
            if (! isset($devNames[$name])) {
                $nonDev[$name] = $requires;
            }
        }

        return ['all' => $all, 'nonDev' => $nonDev];
    }
}
