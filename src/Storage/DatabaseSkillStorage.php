<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use NeuronAI\AgentSkills\ResourceLocator;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/** Read-only storage over application-owned rows identified by (skill_identifier, path). */
class DatabaseSkillStorage implements SkillStorageInterface
{
    private ResourceLocator $resourceLocator;

    public function __construct(
        private PDO $pdo,
        private string $table = 'skills',
        string $baseUri = 'db://skills/'
    ) {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $table) !== 1) {
            throw new RuntimeException('Skill table must be a simple SQL identifier.');
        }

        if (preg_match('~^db://[a-z0-9-]+/(?:[a-z0-9-]+/)*$~D', $baseUri) !== 1) {
            throw new RuntimeException('Skill base URI must be a complete db:// URI ending in a slash.');
        }

        $this->resourceLocator = new ResourceLocator($baseUri);
    }

    public function list(): array
    {
        $locations = [];

        try {
            /** @var PDOStatement $statement */
            $statement = $this->pdo->query('SELECT skill_identifier FROM '.$this->table);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf('Database skill table "%s" could not be read.', $this->table),
                previous: $exception
            );
        }

        foreach ($rows as $row) {
            $location = $this->resourceLocator->fromSkillIdentifier($row['skill_identifier']);
            $locations[$location] = true;
        }

        return array_keys($locations);
    }

    public function read(string $location, string $path): string
    {
        $resource = $this->resourceLocator->resolve($location, $path);

        try {
            $sql = 'SELECT content FROM '.$this->table.' WHERE skill_identifier = ? AND path = ?';
            /** @var PDOStatement $statement */
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$resource->skillIdentifier, $resource->path]);
            $content = $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf('Database skill table "%s" could not be read.', $this->table),
                previous: $exception
            );
        }

        if ($content === false) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $resource->path, $location));
        }

        return $content;
    }
}
