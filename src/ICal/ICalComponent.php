<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

/**
 * A parsed iCalendar component (VCALENDAR, VEVENT, VALARM, VTIMEZONE, …).
 *
 * @internal used by ICalParser
 */
final class ICalComponent
{
    /** @var array<string, list<array{params: array<string, string>, value: string}>> */
    public array $props = [];

    /** @var list<ICalComponent> */
    public array $children = [];

    public function __construct(public readonly string $name)
    {
    }

    /** @param array<string, string> $params */
    public function addProperty(string $name, array $params, string $value): void
    {
        $this->props[$name][] = ['params' => $params, 'value' => $value];
    }

    /**
     * Depth-first search for descendants with the given name.
     *
     * @return list<ICalComponent>
     */
    public function find(string $name): array
    {
        $found = [];
        foreach ($this->children as $child) {
            if ($child->name === $name) {
                $found[] = $child;
            } else {
                array_push($found, ...$child->find($name));
            }
        }
        return $found;
    }
}
