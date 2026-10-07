<?php

declare(strict_types=1);

namespace Vaults\Composer;

final readonly class RepositoryLayout
{
    public const string MergedNote = 'The private Vaults repository serves this project\'s public packages too, so one entry is enough.';

    public const string RemovalQuestion = 'Remove the public Vaults repository from composer.json? The private one serves the same packages.';

    public const string RemovalDeclined = 'Kept the public Vaults repository. Both entries work; the private one answers first.';

    public function __construct(
        public bool $merged,
        public bool $privateConfigured,
        public bool $publicConfigured,
        public ?string $publicUrl = null,
    ) {}

    public static function detect(ComposerConfigWriter $writer, string $directory, bool $merged, mixed $privateUrl, mixed $publicUrl): self
    {
        $publicUrl = is_string($publicUrl) && $publicUrl !== '' ? $publicUrl : null;

        return new self(
            $merged,
            is_string($privateUrl) && $privateUrl !== '' && $writer->hasRepository($directory, $privateUrl),
            $publicUrl !== null && $writer->hasRepository($directory, $publicUrl),
            $publicUrl,
        );
    }

    public function usesMergedRepository(): bool
    {
        return $this->merged && $this->privateConfigured;
    }

    public function canDropPublicEntry(): bool
    {
        return $this->usesMergedRepository() && $this->publicConfigured && $this->publicUrl !== null;
    }
}
