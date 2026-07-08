<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\Support;

/**
 * Test-only helper that fakes a Doctrine `#[GeneratedValue]` identity on an entity built with
 * `new` instead of persistence — the standard technique for exercising entity behavior that
 * reads `getId()` without paying for a database round-trip.
 */
final class EntityIdSetter
{
    public static function set(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
