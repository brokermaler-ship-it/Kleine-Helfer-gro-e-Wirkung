<?php

declare(strict_types=1);

namespace Emc\Core;

final class Container
{
    /** @var array<string,callable> */
    private array $fabriken = [];
    /** @var array<string,mixed> */
    private array $instanzen = [];

    public function set(string $id, callable $fabrik): void
    {
        $this->fabriken[$id] = $fabrik;
        unset($this->instanzen[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instanzen)) {
            return $this->instanzen[$id];
        }
        if (!isset($this->fabriken[$id])) {
            throw new \LogicException('Dienst nicht registriert: ' . $id);
        }
        return $this->instanzen[$id] = ($this->fabriken[$id])($this);
    }
}
