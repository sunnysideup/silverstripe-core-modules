<?php

namespace Sunnysideup\CoreModules\Tasks;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use SilverStripe\PolyExecution\PolyOutput;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SilverStripe\Control\Director;
use SilverStripe\Core\Flushable;
use SilverStripe\Dev\BuildTask;

class PruneProjectComposerRequirements extends BuildTask implements Flushable
{
    protected string $title = 'Prune Project Composer Requirements';

    protected static string $description = 'Removes all requirements from the core that are also required by vendor packages.';

    protected static string $commandName = 'prune-project-composer-requirements';

    private static $run_on_flush = true;

    public static function flush()
    {
        // @TODO (SS6 upgrade): flush() cannot receive $output; skipping auto-run on flush.
        // Previously called singleton(self::class)->run(null) which is no longer valid.
    }

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

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        // Base folder containing composer.json and vendor directory
        $baseFolder = Director::baseFolder();
        try {
            $this->removeUnusedPackages($baseFolder, $output);
        } catch (RuntimeException $runtimeException) {
            $output->writeln('Error: ' . $runtimeException->getMessage());
        }

        return Command::SUCCESS;
    }

    protected function removeUnusedPackages(string $basePath, PolyOutput $output): void
    {
        $baseComposerPath = rtrim($basePath, '/') . '/composer.json';
        $skip = $this->config()->get('packages_to_skip');

        if (! file_exists($baseComposerPath)) {
            throw new RuntimeException('Base composer.json not found at: ' . $baseComposerPath);
        }

        // Load and decode the base composer.json
        $baseComposer = json_decode(file_get_contents($baseComposerPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in base composer.json.');
        }

        if (! isset($baseComposer['require'])) {
            throw new RuntimeException('Base composer.json does not have a "require" section.');
        }

        $baseRequire = $baseComposer['require'];

        $composerFiles = $this->getComposerFiles();

        foreach ($composerFiles as $packageComposerPath) {
            $packageComposer = json_decode(file_get_contents($packageComposerPath), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $output->writeln('Skipping invalid JSON file: ' . $packageComposerPath);
                continue;
            }

            if (! isset($packageComposer['require'])) {
                $output->writeln('Skipping file without "require" section: ' . $packageComposerPath);
                continue;
            }

            $packageRequire = $packageComposer['require'];
            $packageName = $packageComposer['name'] ?? 'unknown';
            foreach (array_keys($packageRequire) as $package) {
                if (in_array($package, $skip)) {
                    continue;
                }

                if (isset($baseRequire[$package])) {
                    $output->writeln('Removing ' . $package . ' from ' . $baseComposerPath . ' as it is also required by ' . $packageName);
                    unset($baseRequire[$package]);
                }
            }
        }

        $baseComposer['require'] = $baseRequire;

        $jsonData = json_encode($baseComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ((bool) $jsonData === false) {
            throw new RuntimeException('Failed to encode JSON for base composer.json: ' . json_last_error_msg());
        }

        file_put_contents($baseComposerPath, $jsonData);

        $output->writeln('Unused packages removed from ' . $baseComposerPath);
    }

    protected function getComposerFiles(): array
    {
        $composerFiles = [];
        $vendorDir = $this->getVendorDir();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($vendorDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'composer.json') {
                $relativePath = str_replace($vendorDir . '/', '', $file->getPath());
                $pathParts = explode('/', $relativePath);

                // Ensure the path matches vendor/vendor-name/package-name/composer.json
                if (count($pathParts) === 2) {
                    $composerFiles[] = $file->getRealPath();
                }
            }
        }

        return $composerFiles;
    }

    public function getVendorDir(): string
    {
        return Director::baseFolder() . '/vendor';
    }
}
