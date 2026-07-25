<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use FloCMS\Core\Modules\Exceptions\InvalidModuleException;

final class ModuleDependency
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $minimumVersion = null
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
            throw new InvalidModuleException('Invalid module dependency id: ' . $id);
        }

        if ($minimumVersion !== null && !ModuleDefinition::isValidVersion($minimumVersion)) {
            throw new InvalidModuleException(
                sprintf('Invalid minimum version "%s" for dependency %s.', $minimumVersion, $id)
            );
        }
    }

    /** @param array<string, mixed>|string $value */
    public static function fromArray(array|string $value): self
    {
        if (is_string($value)) {
            return new self(strtolower(trim($value)));
        }

        $minimumVersion = trim((string) ($value['minimum_version'] ?? ''));

        return new self(
            strtolower(trim((string) ($value['id'] ?? ''))),
            $minimumVersion !== '' ? $minimumVersion : null
        );
    }

    /** @return array{id: string, minimum_version?: string} */
    public function toArray(): array
    {
        $dependency = ['id' => $this->id];
        if ($this->minimumVersion !== null) {
            $dependency['minimum_version'] = $this->minimumVersion;
        }

        return $dependency;
    }
}
