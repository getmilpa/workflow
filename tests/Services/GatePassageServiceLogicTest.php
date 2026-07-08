<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\Services;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Enums\GatePassageStatus;
use Milpa\Workflow\Exceptions\NonWaivableGateException;
use Milpa\Workflow\Exceptions\SelfApprovalException;
use Milpa\Workflow\Services\GatePassageService;

/**
 * Business-logic coverage of {@see GatePassageService} against a **mocked**
 * `EntityManagerInterface` (`persist`/`flush` as no-ops) — no repository queries are involved
 * in `requestPassage()`/`approvePassage()`/`rejectPassage()`/`waiveGate()`, so a mock is
 * enough; no database is needed. {@see GatePassageServiceRepositoryTest} covers
 * `getApprovedPassagesForEntity()`, which genuinely needs a working `QueryBuilder` and so runs
 * against the in-memory SQLite `EntityManager` instead — see that class's docblock for why.
 *
 * The self-approval assertions here are the regression test for the map's residue #2: the
 * comparison is now between two opaque principal strings (e.g. "member:7"), not raw ints.
 */
final class GatePassageServiceLogicTest extends TestCase
{
    private function service(): GatePassageService
    {
        return new GatePassageService($this->createStub(EntityManagerInterface::class));
    }

    public function testRequestPassageStoresTheOpaqueRequester(): void
    {
        $gate = (new GateDefinition())->setCode('qualification_gate');

        $passage = $this->service()->requestPassage($gate, 'opportunity', 99, 'member:7');

        $this->assertSame('member:7', $passage->getRequestedBy());
        $this->assertSame(GatePassageStatus::REQUESTED, $passage->getStatus());
    }

    public function testApprovePassageThrowsSelfApprovalExceptionWhenPrincipalsMatch(): void
    {
        $gate = (new GateDefinition())->setCode('qualification_gate');
        $passage = $this->service()->requestPassage($gate, 'opportunity', 99, 'member:7');

        $this->expectException(SelfApprovalException::class);
        $this->expectExceptionMessage("'member:7'");

        $this->service()->approvePassage($passage, 'member:7');
    }

    public function testApprovePassageSucceedsForADifferentPrincipal(): void
    {
        $gate = (new GateDefinition())->setCode('qualification_gate');
        $passage = $this->service()->requestPassage($gate, 'opportunity', 99, 'member:7');

        $approved = $this->service()->approvePassage($passage, 'member:12', 'looks good');

        $this->assertTrue($approved->isApproved());
        $this->assertSame('member:12', $approved->getApprovedBy());
    }

    public function testSelfApprovalExceptionCarriesTheOpaquePrincipalAndGateCode(): void
    {
        $gate = (new GateDefinition())->setCode('qualification_gate');
        $passage = $this->service()->requestPassage($gate, 'opportunity', 99, 'member:7');

        try {
            $this->service()->approvePassage($passage, 'member:7');
            $this->fail('Expected SelfApprovalException');
        } catch (SelfApprovalException $e) {
            $this->assertSame('member:7', $e->getPrincipal());
            $this->assertSame('qualification_gate', $e->getGateCode());
        }
    }

    public function testRejectPassageMarksItRejectedWithAReason(): void
    {
        $gate = (new GateDefinition())->setCode('qualification_gate');
        $passage = $this->service()->requestPassage($gate, 'opportunity', 99, 'member:7');

        $rejected = $this->service()->rejectPassage($passage, 'member:12', 'missing SOW');

        $this->assertTrue($rejected->isRejected());
        $this->assertSame('missing SOW', $rejected->getRejectedReason());
        $this->assertSame('member:12', $rejected->getApprovedBy());
    }

    public function testWaiveGateThrowsWhenTheGateIsNotWaivable(): void
    {
        $gate = (new GateDefinition())->setCode('sow_signed_gate')->setIsWaivable(false);

        $this->expectException(NonWaivableGateException::class);

        $this->service()->waiveGate($gate, 'opportunity', 99, 'member:1', 'emergency override');
    }

    public function testWaiveGateCreatesAnApprovedSelfResolvedPassage(): void
    {
        $gate = (new GateDefinition())->setCode('sow_signed_gate')->setIsWaivable(true);

        $passage = $this->service()->waiveGate($gate, 'opportunity', 99, 'member:1', 'emergency override');

        $this->assertTrue($passage->isWaiver());
        $this->assertTrue($passage->isApproved());
        $this->assertSame('member:1', $passage->getRequestedBy());
        $this->assertSame('member:1', $passage->getApprovedBy());
        $this->assertSame('emergency override', $passage->getWaiverJustification());
    }
}
