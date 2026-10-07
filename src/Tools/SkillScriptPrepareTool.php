<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tools;

use RuntimeException;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

/** Make skill resources available locally for a separate execution tool. */
class SkillScriptPrepareTool extends Tool
{
    protected string $name = 'skill_script_prepare';

    protected ?string $description = 'Prepare every resource of a skill for script execution. Returns the original local directory or a complete temporary copy. Use a separate execution tool from the returned directory.';

    public function __construct(private SkillRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The declared name of the skill to prepare completely.',
                required: true,
                enum: array_values(array_unique($this->repository->names())),
            ),
        ];
    }

    /** @return array{directory: string, files: list<string>}|ToolOutput */
    public function __invoke(string $name): array|ToolOutput
    {
        try {
            $skill = $this->repository->getByName($name);
            $paths = $skill->resources();

            if (str_starts_with($skill->location(), 'file://')) {
                $directory = rawurldecode(substr($skill->location(), 7));
                if (!str_starts_with($directory, '/') || str_starts_with($directory, '//')
                    || strpbrk($directory, "\0\\") !== false || !is_dir($directory)) {
                    throw new RuntimeException('The local skill directory is invalid or unavailable.');
                }

                return ['directory' => $directory, 'files' => $paths];
            }

            $resources = [];

            foreach ($paths as $path) {
                if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
                    || str_contains($path, "\0") || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path)
                    || preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*:~', $path)) {
                    throw new RuntimeException('Use literal relative file paths without dot segments.');
                }

                $resources[$path] = $skill->readResource($path);
            }

            $directory = sys_get_temp_dir().'/skill-script-'.bin2hex(random_bytes(16));
            if (!@mkdir($directory, 0o700)) {
                throw new RuntimeException('Could not create the temporary skill directory.');
            }

            foreach ($resources as $path => $content) {
                $destination = $directory.'/'.$path;
                $parent = dirname($destination);

                if (!is_dir($parent) && !@mkdir($parent, 0o700, true)) {
                    throw new RuntimeException('Could not create a directory for resource "'.$path.'".');
                }
                if (@file_put_contents($destination, $content) !== strlen($content)) {
                    throw new RuntimeException('Could not save resource "'.$path.'".');
                }
            }

            return ['directory' => $directory, 'files' => array_values(array_unique($paths))];
        } catch (RuntimeException $exception) {
            return ToolOutput::error($exception->getMessage());
        }
    }
}
