<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use NeuronAI\AgentSkills\StoragePaths;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/** Read-only storage over application-owned rows identified by (skill_identifier, path). */
class DatabaseSkillStorage implements SkillStorageInterface
{
    private StoragePaths $storagePaths;

    public function __construct(
        private PDO $pdo,
        private string $table = 'skills'
    ) {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $table) !== 1) {
            throw new RuntimeException('Skill table must be a simple SQL identifier.');
        }

        $this->storagePaths = new StoragePaths();
    }

    public function list(): array
    {
        $identifiers = [];

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
            $skillIdentifier = $row['skill_identifier'];
            $this->storagePaths->validateIdentifier($skillIdentifier);
            $identifiers[$skillIdentifier] = true;
        }

        return array_map(strval(...), array_keys($identifiers));
    }

    public function resources(string $skillIdentifier): array
    {
        $this->storagePaths->validateIdentifier($skillIdentifier);

        try {
            /** @var PDOStatement $statement */
            $statement = $this->pdo->prepare('SELECT path FROM '.$this->table.' WHERE skill_identifier = ?');
            $statement->execute([$skillIdentifier]);
            $paths = $statement->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf('Database skill table "%s" could not be read.', $this->table),
                previous: $exception
            );
        }

        if ($paths === []) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $skillIdentifier));
        }

        foreach ($paths as $path) {
            if ($this->storagePaths->normalizePath($path) !== $path) {
                throw new RuntimeException(sprintf('Resource path "%s" is invalid.', $path));
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    public function read(string $skillIdentifier, string $path): string
    {
        $this->storagePaths->validateIdentifier($skillIdentifier);
        $path = $this->storagePaths->normalizePath($path);

        try {
            $sql = 'SELECT content FROM '.$this->table.' WHERE skill_identifier = ? AND path = ?';
            /** @var PDOStatement $statement */
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$skillIdentifier, $path]);
            $content = $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf('Database skill table "%s" could not be read.', $this->table),
                previous: $exception
            );
        }

        if ($content === false) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $path, $skillIdentifier));
        }

        return $content;
    }
}
