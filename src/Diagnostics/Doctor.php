<?php

declare(strict_types=1);

namespace Vaults\Diagnostics;

use Vaults\Auth\TokenStore;
use Vaults\Exception\VaultsException;
use Vaults\VaultsClient;

final readonly class Doctor
{
    public const array EdgeHosts = ['dist.vaults-edge.net', 'repo.vaults-edge.net'];

    public const string PrivateHost = 'private.vaults-edge.net';

    public const string PublicHost = 'repo.vaults-edge.net';

    public function __construct(
        private VaultsClient $client,
        private TokenStore $store,
        private EdgeProbe $probe,
        private string $loginHint = 'run vaults login',
    ) {}

    public function run(string $directory): DoctorReport
    {
        $rows = [];

        $apiReachable = $this->apiReachable();
        $healthy = $apiReachable;
        $rows[] = ['Vaults API', $apiReachable ? '✓' : '✗ unreachable'];

        [$authRow, $authHealthy] = $this->authentication();
        $healthy = $healthy && $authHealthy;
        $rows[] = $authRow;

        $rows[] = ['composer.lock present', is_file($directory.DIRECTORY_SEPARATOR.'composer.lock') ? '✓' : '✗ (not a composer project?)'];

        $rows[] = ['Vaults repositories', $this->repositoryLayout($directory)];

        foreach (self::EdgeHosts as $hostname) {
            $answers = $this->probe->resolve($hostname);
            $resolved = $answers !== [];
            $served = $resolved && $this->probe->healthCheck($hostname);
            $healthy = $healthy && $served;

            $rows[] = ['DNS '.$hostname, $resolved ? '✓ '.implode(', ', array_slice($answers, 0, 2)) : '✗ no answer'];
            $rows[] = ['Edge health '.$hostname, $served ? '✓' : '✗'];
        }

        return new DoctorReport($rows, $healthy);
    }

    private function repositoryLayout(string $directory): string
    {
        $path = $directory.DIRECTORY_SEPARATOR.'composer.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $repositories = is_array($decoded) && is_array($decoded['repositories'] ?? null) ? $decoded['repositories'] : [];
        $hosts = [];

        foreach ($repositories as $entry) {
            $host = is_array($entry) ? parse_url((string) ($entry['url'] ?? ''), PHP_URL_HOST) : null;

            if (is_string($host)) {
                $hosts[] = $host;
            }
        }

        $private = in_array(self::PrivateHost, $hosts, true);
        $public = in_array(self::PublicHost, $hosts, true);

        return match (true) {
            $private && $public => '✓ private and public (two entries)',
            $private => '✓ one private repository, serving public packages too',
            $public => '✓ public only',
            default => '- not configured (run a deposit)',
        };
    }

    private function apiReachable(): bool
    {
        try {
            return $this->client->ping();
        } catch (VaultsException) {
            return false;
        }
    }

    /**
     * @return array{0: array{0: string, 1: string}, 1: bool}
     */
    private function authentication(): array
    {
        if ($this->store->token() === null) {
            return [['Authentication', '- not logged in ('.$this->loginHint.')'], true];
        }

        try {
            $team = $this->client->whoami();

            return [['Authentication', '✓ team: '.($team->name ?? 'unknown')], true];
        } catch (VaultsException) {
            return [['Authentication', '✗ stored token was rejected'], false];
        }
    }
}
