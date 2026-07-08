<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\Services;

use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Services\GatePassageService;
use Milpa\Workflow\Tests\Support\InMemoryEntityManagerFactory;

/**
 * Repository-backed coverage of {@see GatePassageService::getApprovedPassagesForEntity()}:
 * it builds and runs a real `QueryBuilder`, which is impractical to mock faithfully, so this
 * one method runs against the in-memory SQLite `EntityManager`
 * ({@see InMemoryEntityManagerFactory}) rather than a stub. Every other method on the service
 * is covered without a database in `GatePassageServiceLogicTest`.
 */
final class GatePassageServiceRepositoryTest extends TestCase
{
    private EntityManager $em;
    private GatePassageService $service;
    private GateDefinition $gate;

    protected function setUp(): void
    {
        $this->em = InMemoryEntityManagerFactory::create();
        $this->service = new GatePassageService($this->em);

        $this->gate = (new GateDefinition())->setDomain('opportunity')->setCode('qualification_gate')->setName('Qualification Gate')
            ->setRequesterRole('sales')->setApproverRole('manager');
        $this->em->persist($this->gate);
        $this->em->flush();
    }

    public function testGetApprovedPassagesForEntityOnlyReturnsApprovedOnesForThatEntity(): void
    {
        $approved = $this->service->requestPassage($this->gate, 'opportunity', 99, 'member:7');
        $this->service->approvePassage($approved, 'member:12');

        $stillPending = $this->service->requestPassage($this->gate, 'opportunity', 99, 'member:8');

        // Different entity id — must not show up in the results below.
        $otherEntity = $this->service->requestPassage($this->gate, 'opportunity', 100, 'member:9');
        $this->service->approvePassage($otherEntity, 'member:13');

        $this->em->clear();

        $results = $this->service->getApprovedPassagesForEntity('opportunity', 99);

        $this->assertCount(1, $results);
        $this->assertSame('member:7', $results[0]->getRequestedBy());
        $this->assertTrue($results[0]->isApproved());
    }

    public function testGetApprovedPassagesForEntityReturnsEmptyWhenNoneAreApproved(): void
    {
        $this->service->requestPassage($this->gate, 'opportunity', 42, 'member:1');

        $this->em->clear();

        $this->assertSame([], $this->service->getApprovedPassagesForEntity('opportunity', 42));
    }
}
