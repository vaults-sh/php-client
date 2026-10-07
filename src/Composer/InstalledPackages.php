<?php

declare(strict_types=1);

namespace Vaults\Composer;

use stdClass;

final class InstalledPackages
{
    /**
     * @return int the number of installed records whose dist URL now matches the lock
     */
    public function syncDistUrls(string $directory, string $lockContents): int
    {
        $path = $this->installedPath($directory);
        $installed = is_file($path) ? json_decode((string) file_get_contents($path)) : null;

        if (! $installed instanceof stdClass || ! is_array($installed->packages ?? null)) {
            return 0;
        }

        $locked = $this->lockedDistUrls($lockContents);
        $changed = 0;

        foreach ($installed->packages as $package) {
            if (! $package instanceof stdClass || ! ($package->dist ?? null) instanceof stdClass) {
                continue;
            }

            $url = $locked[$this->identity($package->name ?? null, $package->version ?? null, $package->dist->reference ?? null)] ?? null;

            if ($url === null || ($package->dist->url ?? null) === $url) {
                continue;
            }

            $package->dist->url = $url;
            $changed++;
        }

        if ($changed === 0) {
            return 0;
        }

        $encoded = json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) && file_put_contents($path, $encoded.PHP_EOL) !== false ? $changed : 0;
    }

    /**
     * @return array<string, string>
     */
    private function lockedDistUrls(string $lockContents): array
    {
        $lock = json_decode($lockContents, true);
        $urls = [];

        if (! is_array($lock)) {
            return $urls;
        }

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                $url = is_array($package) ? ($package['dist']['url'] ?? null) : null;

                if (is_string($url) && $url !== '') {
                    $urls[$this->identity($package['name'] ?? null, $package['version'] ?? null, $package['dist']['reference'] ?? null)] = $url;
                }
            }
        }

        return $urls;
    }

    private function identity(mixed $name, mixed $version, mixed $reference): string
    {
        return strtolower(is_string($name) ? $name : '').'@'.(is_string($version) ? $version : '').'#'.(is_string($reference) ? $reference : '');
    }

    private function installedPath(string $directory): string
    {
        $composerJson = $directory.DIRECTORY_SEPARATOR.'composer.json';
        $decoded = is_file($composerJson) ? json_decode((string) file_get_contents($composerJson), true) : null;
        $vendorDir = is_array($decoded) && is_string($decoded['config']['vendor-dir'] ?? null) && $decoded['config']['vendor-dir'] !== ''
            ? $decoded['config']['vendor-dir']
            : 'vendor';

        $base = str_starts_with($vendorDir, DIRECTORY_SEPARATOR) ? $vendorDir : $directory.DIRECTORY_SEPARATOR.$vendorDir;

        return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'composer'.DIRECTORY_SEPARATOR.'installed.json';
    }
}
