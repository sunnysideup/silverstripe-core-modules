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
        $vendorDir = $basePath . '/vendor';

        if (!is_dir($vendorDir)) {
            throw new RuntimeException('Vendor directory not found: ' . $vendorDir);
        }

        // Loop through every composer.json in vendor/vendor-name/package-name
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($vendorDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getFilename() === 'composer.json') {
                $packageComposerPath = $file->getPathname();
                $packageComposer = json_decode(file_get_contents($packageComposerPath), true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    DB::alteration_message('Skipping invalid JSON file: ' . $packageComposerPath);
                    continue;
                }

                if (!isset($packageComposer['require'])) {
                    DB::alteration_message('Skipping file without "require" section: ' . $packageComposerPath);
                    continue;
                }

                $packageRequire = $packageComposer['require'];

                // Remove packages from base require if they are not used in the package
                foreach ($baseRequire as $package => $version) {
                    if (!isset($packageRequire[$package])) {
                        DB::alteration_message('Removing ' . $package . ' from ' . $packageComposerPath, 'deleted');
                        unset($baseRequire[$package]);
                    }
                }
            }
        }

        // Update the base composer.json
        $baseComposer['require'] = $baseRequire;
        file_put_contents(
            $baseComposerPath,
            json_encode($baseComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        DB::alteration_message('Unused packages removed from ' . $baseComposerPath);
    }


}
