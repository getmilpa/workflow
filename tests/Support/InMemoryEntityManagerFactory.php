<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Builds a fresh, fully in-memory {@see EntityManager} (SQLite `:memory:`, schema created
 * from this package's own attribute-mapped entities) for tests that exercise repository
 * queries (`findBy`/`findOneBy`/`QueryBuilder`). No live MySQL, no fixtures on disk, no
 * network — the whole database lives and dies with the PHP process running the test.
 *
 * This is the "Doctrine in-memory tooling" boundary: the package's own entities are
 * genuinely ORM-backed (that is the point of the package), so a subset of tests need a
 * real, working `EntityManagerInterface` rather than a hand-mocked one. Everything that
 * does NOT need repository queries (gate evaluation, the state-machine/verifier bridge,
 * entity value objects) is tested without touching this factory at all.
 */
final class InMemoryEntityManagerFactory
{
    public static function create(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [dirname(__DIR__, 2) . '/src/Entities'],
            isDevMode: true,
        );

        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $config,
        );

        $em = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $em;
    }
}
