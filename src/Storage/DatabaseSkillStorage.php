<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use PDO;
use PDOException;
use RuntimeException;

/** Read-only storage over application-owned rows identified by (skill_name, path). */
class DatabaseSkillStorage implements SkillStorageInterface
{
    public function __construct(private string $mount, private PDO $pdo, private string $table = 'skills')
    {
        if (preg_match('~^db://[a-z0-9-]+/(?:[a-z0-9-]+/)*$~D', $mount) !== 1) {
            throw new RuntimeException('Skill mount must be a complete db:// mount ending in a slash.');
        }
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $table) !== 1) {
            throw new RuntimeException('Skill table must be a simple SQL identifier.');
        }
    }

    public function list(): array
    {
        $locations = [];
        foreach ($this->select('SELECT skill_name FROM '.$this->table) as $row) {
            $locations[$this->mount.rawurlencode($row[0]).'/'] = true;
        }
        return array_keys($locations);
    }

    public function read(string $location, string $path): string
    {
        $skill = rawurldecode(substr($location, strlen($this->mount), -1));
        if (!str_starts_with($location, $this->mount) || $skill === ''
            || $location !== $this->mount.rawurlencode($skill).'/') {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $location));
        }
        $path = $this->relativePath($path);
        // SQL narrows candidates; exact identity must not depend on database collation.
        $rows = array_values(array_filter(
            $this->select('SELECT skill_name, path, content FROM '.$this->table.' WHERE skill_name = ? AND path = ?', [$skill, $path]),
            static fn (array $row): bool => $row[0] === $skill && $row[1] === $path,
        ));
        if ($rows === []) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $path, $location));
        }
        $content = $rows[0][2];
        if (!is_string($content) || str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" contains unsupported binary content.', $path, $location));
        }
        return $content;
    }

    /**
     * @param list<string> $parameters
     * @return list<list<mixed>>
     */
    private function select(string $sql, array $parameters = []): array
    {
        $message = sprintf('Database skill table "%s" could not be read.', $this->table);
        try {
            // Preserve the caller's PDO error mode, translating both false returns and exceptions.
            $statement = @$this->pdo->prepare($sql);
            if ($statement === false || !@$statement->execute($parameters)) {
                throw new RuntimeException($message);
            }
            $rows = @$statement->fetchAll(PDO::FETCH_NUM);
            if ($statement->errorCode() !== '00000') {
                throw new RuntimeException($message);
            }
            return $rows;
        } catch (PDOException $exception) {
            throw new RuntimeException($message, 0, $exception);
        }
    }

    private function relativePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path) === 1) {
            throw new RuntimeException(sprintf('Resource path "%s" is invalid.', $path));
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new RuntimeException(sprintf('Resource path "%s" escapes the skill root.', $path));
                }
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        if ($segments === []) {
            throw new RuntimeException(sprintf('Resource path "%s" is invalid.', $path));
        }
        return implode('/', $segments);
    }
}
