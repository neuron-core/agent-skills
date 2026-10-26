<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills;

use RuntimeException;
use Throwable;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;

use function array_filter;
use function array_map;
use function array_values;
use function sort;
use function sprintf;

use const SORT_STRING;

class SkillRepository
{
    protected const MANIFEST = 'SKILL.md';

    /** @var list<Skill> */
    protected array $catalog = [];

    /** @var list<array{storage: SkillStorageInterface, skillIdentifier: string, message: string}> */
    protected array $diagnostics = [];

    /** @var array<int, SkillStorageInterface> */
    private array $pendingStorages = [];

    /** @return list<array{storage: SkillStorageInterface, skillIdentifier: string, message: string}> */
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
    public function getByName(string $name): Skill
    {
        $matches = $this->findByName($name);

        if ($matches === []) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $name));
        }

        return $matches[0];
    }

    /** @return list<Skill> */
    public function findByName(string $name): array
    {
        return array_values(array_filter(
            $this->catalog(),
            static fn (Skill $skill): bool => $skill->name() === $name,
        ));
    }

    /** @return list<Skill> */
    private function resolveCatalog(): array
    {
        foreach ($this->pendingStorages as $index => $storage) {
            $catalog = $this->catalog;
            $diagnostics = $this->diagnostics;

            try {
                $this->buildCatalog($storage);
            } catch (Throwable $exception) {
                $this->catalog = $catalog;
                $this->diagnostics = $diagnostics;
                throw $exception;
            }

            unset($this->pendingStorages[$index]);
        }

        return $this->catalog;
    }

    protected function buildCatalog(SkillStorageInterface $storage): void
    {
        $skillIdentifiers = $storage->list();
        sort($skillIdentifiers, SORT_STRING);

        $seen = [];
        foreach ($skillIdentifiers as $skillIdentifier) {
            if (isset($seen[$skillIdentifier])) {
                throw new RuntimeException(sprintf('Duplicate skill identifier "%s" within one storage.', $skillIdentifier));
            }

            $seen[$skillIdentifier] = true;
        }

        foreach ($skillIdentifiers as $skillIdentifier) {
            try {
                $contents = $storage->read($skillIdentifier, self::MANIFEST);
            } catch (RuntimeException $exception) {
                $this->diagnostics[] = ['storage' => $storage, 'skillIdentifier' => $skillIdentifier, 'message' => $exception->getMessage()];
                continue;
            }

            $parsed = (new SkillDocumentParser())->parse($contents);
            foreach ($parsed['warnings'] as $message) {
                $this->diagnostics[] = ['storage' => $storage, 'skillIdentifier' => $skillIdentifier, 'message' => $message];
            }

            $document = $parsed['document'];
            if ($document === null) {
                continue;
            }

            $this->catalog[] = new Skill(
                $document['name'],
                $document['description'],
                $skillIdentifier,
                $storage
            );
        }
    }
}
