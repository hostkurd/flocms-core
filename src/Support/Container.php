<?php
declare(strict_types=1);

namespace FloCMS\Core\Support;

use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;

final class Container implements ContainerInterface
{
    /** @var array<string, callable(self): mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function bind(string $id, callable|string $concrete): self
    {
        $this->bindings[$id] = is_string($concrete)
            ? static fn (self $container): mixed => $container->build($concrete)
            : $concrete(...);
        unset($this->instances[$id]);

        return $this;
    }

    public function singleton(string $id, callable|string $concrete): self
    {
        $factory = is_string($concrete)
            ? static fn (self $container): mixed => $container->build($concrete)
            : $concrete(...);

        $this->bindings[$id] = function (self $container) use ($id, $factory): mixed {
            return $this->instances[$id] ??= $factory($container);
        };

        return $this;
    }

    public function instance(string $id, mixed $value): self
    {
        $this->instances[$id] = $value;
        unset($this->bindings[$id]);

        return $this;
    }

    public function alias(string $abstract, string $target): self
    {
        return $this->bind($abstract, static fn (self $container): mixed => $container->get($target));
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances)
            || array_key_exists($id, $this->bindings)
            || class_exists($id);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->resolving[$id])) {
            throw new ContainerException('Circular service dependency while resolving ' . $id . '.');
        }

        $this->resolving[$id] = true;

        try {
            if (isset($this->bindings[$id])) {
                return ($this->bindings[$id])($this);
            }

            if (!class_exists($id)) {
                throw new ContainerException('Service is not bound and is not an instantiable class: ' . $id);
            }

            return $this->build($id);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    public function build(string $class): object
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $error) {
            throw new ContainerException('Unable to reflect service ' . $class . '.', 0, $error);
        }

        if (!$reflection->isInstantiable()) {
            throw new ContainerException('Service is not instantiable: ' . $class);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new ContainerException(
                sprintf(
                    'Cannot resolve parameter $%s while building %s.',
                    $parameter->getName(),
                    $class
                )
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
