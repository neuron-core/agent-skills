<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills;

use RuntimeException;
use Throwable;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;

use function array_key_exists;
use function array_filter;
use function array_map;
use function array_values;
use function sort;
use function sprintf;

use const SORT_STRING;

class SkillRepository
{
    protected const MANIFEST = 'SKILL.md';

    /** @var array<string, Skill> */
    protected array $catalog = [];

    /** @var list<array{skillLocation: string, message: string}> */
    protected array $diagnostics = [];

    /** @var array<string, true> */
    private array $discoveredLocations = [];

    /** @var array<int, SkillStorageInterface> */
    private array $pendingStorages = [];

    /** @return list<array{skillLocation: string, message: string}> */
    public function diagnostics(): array
    {
        $this->resolveCatalog();

        return $this->diagnostics;
    }

    public function __construct(SkillStorageInterface ...$storages)
    {
        $this->addStorage(...$storages);
    }

    public function addStorage(SkillStorageInterface ...$storages): void
    {
        foreach ($storages as $storage) {
            $this->pendingStorages[] = $storage;
        }
    }

    /** @return list<Skill> */
    public function catalog(): array
    {
        return array_values($this->resolveCatalog());
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (Skill $skill): string => $skill->name(), $this->catalog());
    }

    /** @throws RuntimeException */
    public function get(string $location): Skill
    {
        $catalog = $this->resolveCatalog();

        if (!array_key_exists($location, $catalog)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $location));
        }

        return $catalog[$location];
    }

    /** @return list<Skill> */
    public function findByName(string $name): array
    {
        return array_values(array_filter(
            $this->catalog(),
            static fn (Skill $skill): bool => $skill->name() === $name,
        ));
    }

    /** @return array<string, Skill> */
    private function resolveCatalog(): array
    {
        foreach ($this->pendingStorages as $index => $storage) {
            $catalog = $this->catalog;
            $diagnostics = $this->diagnostics;
            $locations = $this->discoveredLocations;

            try {
                $this->buildCatalog($storage);
            } catch (Throwable $exception) {
                $this->catalog = $catalog;
                $this->diagnostics = $diagnostics;
                $this->discoveredLocations = $locations;
                throw $exception;
            }

            unset($this->pendingStorages[$index]);
        }

        return $this->catalog;
    }

    protected function buildCatalog(SkillStorageInterface $storage): void
    {
        $skillLocations = $storage->list();
        sort($skillLocations, SORT_STRING);

        foreach ($skillLocations as $skillLocation) {
            if (array_key_exists($skillLocation, $this->discoveredLocations)) {
                throw new RuntimeException(sprintf('Duplicate skill location "%s".', $skillLocation));
            }

            $this->discoveredLocations[$skillLocation] = true;
        }

        foreach ($skillLocations as $skillLocation) {
            try {
                $contents = $storage->read($skillLocation, self::MANIFEST);
            } catch (RuntimeException $exception) {
                $this->diagnostics[] = ['skillLocation' => $skillLocation, 'message' => $exception->getMessage()];
                continue;
            }

            $parsed = (new SkillDocumentParser())->parse($contents);
            foreach ($parsed['warnings'] as $message) {
                $this->diagnostics[] = ['skillLocation' => $skillLocation, 'message' => $message];
            }

            $document = $parsed['document'];
            if ($document === null) {
                continue;
            }

            $this->catalog[$skillLocation] = new Skill(
                $document['name'],
                $document['description'],
                $skillLocation,
                $storage
            );
        }
    }
}
