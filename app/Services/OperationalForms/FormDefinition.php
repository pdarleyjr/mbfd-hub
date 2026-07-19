<?php

namespace App\Services\OperationalForms;

use RuntimeException;

final class FormDefinition
{
    private ?array $manifest = null;

    private ?array $mapping = null;

    public function __construct(
        private readonly string $directory,
        private readonly array $capacities,
    ) {}

    public function manifest(): array
    {
        return $this->manifest ??= $this->readJson($this->directory.'/manifest.json');
    }

    public function mapping(): array
    {
        return $this->mapping ??= $this->readJson($this->mappingPath());
    }

    public function templatePath(): string
    {
        return $this->directory.'/template.pdf';
    }

    public function mappingPath(): string
    {
        return $this->directory.'/mapping.json';
    }

    public function capacities(): array
    {
        return $this->capacities;
    }

    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Controlled form asset is unavailable.');
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
