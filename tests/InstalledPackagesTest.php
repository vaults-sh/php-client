<?php

declare(strict_types=1);

use Vaults\Composer\InstalledPackages;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/vaults-installed-'.uniqid();
    mkdir($this->dir.'/vendor/composer', 0755, true);
});

function installedRecord(string $name, string $url, string $reference = 'ref-1'): array
{
    return ['name' => $name, 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => $url, 'reference' => $reference, 'shasum' => ''], 'extra' => new stdClass];
}

it('points installed records at the dist urls the lock now uses', function () {
    file_put_contents($this->dir.'/vendor/composer/installed.json', json_encode([
        'packages' => [
            installedRecord('acme/lib', 'https://satis.example.com/acme-lib.zip'),
            installedRecord('acme/moved-on', 'https://satis.example.com/moved.zip', 'ref-old'),
            installedRecord('acme/untouched', 'https://dist.vaults-edge.net/untouched.zip'),
        ],
        'dev' => true,
        'dev-package-names' => [],
    ]));

    $lock = json_encode([
        'packages' => [
            installedRecord('Acme/Lib', 'https://private.vaults-edge.net/dist/acme-lib.zip'),
            installedRecord('acme/moved-on', 'https://dist.vaults-edge.net/moved.zip', 'ref-new'),
        ],
        'packages-dev' => [installedRecord('acme/untouched', 'https://dist.vaults-edge.net/untouched.zip')],
    ]);

    $changed = (new InstalledPackages)->syncDistUrls($this->dir, $lock);
    $contents = (string) file_get_contents($this->dir.'/vendor/composer/installed.json');
    $installed = json_decode($contents, true);

    expect($changed)->toBe(1)
        ->and($installed['packages'][0]['dist']['url'])->toBe('https://private.vaults-edge.net/dist/acme-lib.zip')
        ->and($installed['packages'][1]['dist']['url'])->toBe('https://satis.example.com/moved.zip')
        ->and($contents)->toContain('"extra": {}')
        ->and($contents)->toContain('"dev-package-names": []');
});

it('leaves the file alone when nothing differs or nothing is installed', function () {
    expect((new InstalledPackages)->syncDistUrls($this->dir, '{"packages":[]}'))->toBe(0);

    $original = json_encode(['packages' => [installedRecord('acme/lib', 'https://dist.vaults-edge.net/a.zip')]]);
    file_put_contents($this->dir.'/vendor/composer/installed.json', $original);

    expect((new InstalledPackages)->syncDistUrls($this->dir, json_encode(['packages' => [installedRecord('acme/lib', 'https://dist.vaults-edge.net/a.zip')]])))->toBe(0)
        ->and((string) file_get_contents($this->dir.'/vendor/composer/installed.json'))->toBe($original);
});

it('follows a custom vendor directory', function () {
    mkdir($this->dir.'/deps/composer', 0755, true);
    file_put_contents($this->dir.'/composer.json', json_encode(['config' => ['vendor-dir' => 'deps']]));
    file_put_contents($this->dir.'/deps/composer/installed.json', json_encode(['packages' => [installedRecord('acme/lib', 'https://old.example.com/a.zip')]]));

    expect((new InstalledPackages)->syncDistUrls($this->dir, json_encode(['packages' => [installedRecord('acme/lib', 'https://dist.vaults-edge.net/a.zip')]])))->toBe(1);
});
