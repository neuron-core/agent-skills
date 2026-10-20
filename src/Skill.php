<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills;

use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use RuntimeException;
use stdClass;

use function sprintf;
use function trim;

/** A discovered skill whose document and resources are read on demand. */
final class Skill
{
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly string $skillIdentifier,
        private readonly SkillStorageInterface $storage,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function identifier(): string
    {
        return $this->skillIdentifier;
    }

    public function storage(): SkillStorageInterface
    {
        return $this->storage;
    }

    /** @throws RuntimeException */
    public function loadInstructions(): string
    {
        return trim($this->parseDocument($this->resource('SKILL.md')->load())['body']);
    }

    /** @throws RuntimeException */
    public function loadFrontmatter(): stdClass
    {
        return $this->parseDocument($this->resource('SKILL.md')->load())['frontmatter'];
    }

    /** @throws RuntimeException */
    public function loadDocument(): string
    {
        $contents = $this->resource('SKILL.md')->load();
        $this->parseDocument($contents);

        return $contents;
    }

    /**
     * @return list<Resource>
     * @throws RuntimeException
     */
    public function resources(): array
    {
        return array_map(
            fn (string $path): Resource => $this->resource($path),
            $this->storage->resources($this->skillIdentifier),
        );
    }

    /** Return a resource without reading or checking its existence. */
    public function resource(string $path): Resource
    {
        return new Resource($this->skillIdentifier, $path, $this->storage);
    }

    /** @return array{name: string, description: string, body: string, frontmatter: stdClass} */
    private function parseDocument(string $contents): array
    {
        $document = (new SkillDocumentParser())->parse($contents)['document'];
        if ($document === null) {
            throw new RuntimeException(sprintf('Skill "%s" has invalid frontmatter.', $this->name));
        }

        return $document;
    }
}
