<?php

namespace Sunnysideup\CoreModules\Tasks;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

class PruneProjectComposerRequirements extends BuildTask
{
    protected $title = 'Prune Project Composer Requirements';

    protected $description = 'Removes all requirements from the core that are also required by vendor packages.';

    private static $segment = 'prune-project-composer-requirements';

    private static $packages_to_skip = [
        'silverstripe/recipe-cms',
        'silverstripe/recipe-core',
        'silverstripe/vendor-plugin',
        'silverstripe/mimevalidator',
        'silverstripe/recipe-plugin',
        'sunnysideup/core-modules',
        'php',
    ];

    public function run($request)
    {
        // Base folder containing composer.json and vendor directory
        $baseFolder = Director::baseFolder();

        try {
            $this->removeUnusedPackages($baseFolder);
        } catch (RuntimeException $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        }

    }

    protected function removeUnusedPackages(string $basePath): void
    {
        $baseComposerPath = rtrim($basePath, '/') . '/composer.json';
        $skip = $this->config()->get('packages_to_skip');

        if (!file_exists($baseComposerPath)) {
            throw new RuntimeException('Base composer.json not found at: ' . $baseComposerPath);
        }

        // Load and decode the base composer.json
        $baseComposer = json_decode(file_get_contents($baseComposerPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in base composer.json.');
        }

        if (!isset($baseComposer['require'])) {
            throw new RuntimeException('Base composer.json does not have a "require" section.');
        }

        $baseRequire = $baseComposer['require'];


        $composerFiles = $this->getComposerFiles();

        foreach ($composerFiles as $packageComposerPath) {
            $packageComposer = json_decode(file_get_contents($packageComposerPath), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                DB::alteration_message('Skipping invalid JSON file: ' . $packageComposerPath, 'error');
                continue;
            }

            if (!isset($packageComposer['require'])) {
                DB::alteration_message('Skipping file without "require" section: ' . $packageComposerPath);
                continue;
            }

            $packageRequire = $packageComposer['require'];
            $packageName = $packageComposer['name'] ?? 'unknown';
            foreach (array_keys($packageRequire) as $package) {
                if (in_array($package, $skip)) {
                    continue;
                }
                if (isset($baseRequire[$package])) {
                    DB::alteration_message('Removing ' . $package . ' from ' . $baseComposerPath . ' as it is also required by '.$packageName, 'deleted');
                    unset($baseRequire[$package]);
                }
            }
        }

        $baseComposer['require'] = $baseRequire;

        $jsonData = json_encode($baseComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($jsonData === false) {
            throw new RuntimeException('Failed to encode JSON for base composer.json: ' . json_last_error_msg());
        }

        file_put_contents($baseComposerPath, $jsonData);

        DB::alteration_message('Unused packages removed from ' . $baseComposerPath, 'created');
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
