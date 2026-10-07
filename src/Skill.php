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
        private readonly string $location,
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

    public function location(): string
    {
        return $this->location;
    }

    /** @throws RuntimeException */
    public function readInstructions(): string
    {
        return trim($this->parseDocument($this->storage->read($this->location, 'SKILL.md'))['body']);
    }

    /** @throws RuntimeException */
    public function readFrontmatter(): stdClass
    {
        return $this->parseDocument($this->storage->read($this->location, 'SKILL.md'))['frontmatter'];
    }

    /** @throws RuntimeException */
    public function readDocument(): string
    {
        $contents = $this->storage->read($this->location, 'SKILL.md');
        $this->parseDocument($contents);

        return $contents;
    }

    /**
     * Read a resource by its literal path relative to this skill, such as references/my guide.md.
     *
     * @throws RuntimeException
     */
    public function readResource(string $path): string
    {
        return $this->storage->read($this->location, $path);
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
