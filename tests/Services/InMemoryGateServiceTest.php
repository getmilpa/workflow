<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\Services;

use PHPUnit\Framework\TestCase;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Enums\ApprovalPolicy;
use Milpa\Workflow\Enums\GatePassageStatus;
use Milpa\Workflow\Exceptions\NonWaivableGateException;
use Milpa\Workflow\Exceptions\SelfApprovalException;
use Milpa\Workflow\Services\InMemoryGateService;

/**
 * Coverage of {@see InMemoryGateService} — the non-Doctrine {@see \Milpa\Workflow\Contracts\GateServiceInterface}
 * implementation for zero-DB / event-sourced consumers. Unlike
 * {@see GatePassageServiceLogicTest} (which stubs `EntityManagerInterface`) and
 * {@see GatePassageServiceRepositoryTest} (which boots an in-memory SQLite `EntityManager`),
 * NOTHING in this file references `Doctrine\ORM` at all — that absence is itself the proof this
 * service needs no `EntityManager`, reinforced explicitly by
 * {@see self::testConstructorNeedsNoArgumentsAndNoDoctrineType()}.
 */
final class InMemoryGateServiceTest extends TestCase
{
    private function gate(ApprovalPolicy $policy = ApprovalPolicy::SINGLE, bool $waivable = true): GateDefinition
    {
        return (new GateDefinition())
            ->setCode('qualification_gate')
            ->setApprovalPolicy($policy)
            ->setIsWaivable($waivable);
    }

    public function testConstructorNeedsNoArgumentsAndNoDoctrineType(): void
    {
        $reflection = new \ReflectionClass(InMemoryGateService::class);
        $constructor = $reflection->getConstructor();

        // Zero-arg construction must work — no dependency is mandatory, Doctrine or otherwise.
        $service = new InMemoryGateService();
        $this->assertInstanceOf(InMemoryGateService::class, $service);

        // Belt-and-braces: assert no constructor parameter type-hints anything under Doctrine\ORM.
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
            $this->assertStringNotContainsStringIgnoringCase('Doctrine', $typeName);
        }
    }

    public function testOpensAndResolvesAGateEndToEndWithZeroDoctrine(): void
    {
        $service = new InMemoryGateService();
        $gate = $this->gate();

        $passage = $service->requestPassage($gate, 'opportunity', 99, 'member:7');
        $this->assertSame(GatePassageStatus::REQUESTED, $passage->getStatus());
        $this->assertSame('member:7', $passage->getRequestedBy());

        $resolved = $service->approvePassage($passage, 'member:12', 'looks good');

        $this->assertTrue($resolved->isApproved());
        $this->assertSame('member:12', $resolved->getApprovedBy());
        $this->assertSame([$resolved], $service->getApprovedPassagesForEntity('opportunity', 99));
    }

    public function testApprovePassageThrowsSelfApprovalExceptionWhenPrincipalsMatch(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(), 'opportunity', 99, 'member:7');

        $this->expectException(SelfApprovalException::class);
        $this->expectExceptionMessage("'member:7'");

        $service->approvePassage($passage, 'member:7');
    }

    public function testSelfApprovalExceptionCarriesTheOpaquePrincipalAndGateCode(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(), 'opportunity', 99, 'member:7');

        try {
            $service->approvePassage($passage, 'member:7');
            $this->fail('Expected SelfApprovalException');
        } catch (SelfApprovalException $e) {
            $this->assertSame('member:7', $e->getPrincipal());
            $this->assertSame('qualification_gate', $e->getGateCode());
        }
    }

    public function testSingleApprovalPolicyResolvesOnTheFirstApproval(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(ApprovalPolicy::SINGLE), 'opportunity', 1, 'member:1');

        $resolved = $service->approvePassage($passage, 'member:2');

        $this->assertTrue($resolved->isApproved());
    }

    public function testDualApprovalPolicyStaysPendingAfterOneDistinctApprover(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(ApprovalPolicy::DUAL), 'opportunity', 1, 'member:1');

        $stillPending = $service->approvePassage($passage, 'member:2');

        $this->assertTrue($stillPending->isPending());
        $this->assertFalse($stillPending->isApproved());
        $this->assertSame([], $service->getApprovedPassagesForEntity('opportunity', 1));
    }

    public function testDualApprovalPolicyResolvesOnceTwoDistinctApproversHaveApproved(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(ApprovalPolicy::DUAL), 'opportunity', 1, 'member:1');

        $service->approvePassage($passage, 'member:2');
        $resolved = $service->approvePassage($passage, 'member:3', 'second sign-off');

        $this->assertTrue($resolved->isApproved());
        $this->assertSame('member:3', $resolved->getApprovedBy());
        $this->assertCount(1, $service->getApprovedPassagesForEntity('opportunity', 1));
    }

    public function testDualApprovalPolicyDoesNotCountTheSameApproverTwice(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(ApprovalPolicy::DUAL), 'opportunity', 1, 'member:1');

        $service->approvePassage($passage, 'member:2');
        $stillPending = $service->approvePassage($passage, 'member:2');

        $this->assertTrue($stillPending->isPending());
    }

    public function testQuorumAndAutoPoliciesResolveOnTheFirstApprovalLikeSingle(): void
    {
        $service = new InMemoryGateService();

        $quorumPassage = $service->requestPassage($this->gate(ApprovalPolicy::QUORUM), 'opportunity', 2, 'member:1');
        $autoPassage = $service->requestPassage($this->gate(ApprovalPolicy::AUTO), 'opportunity', 3, 'member:1');

        $this->assertTrue($service->approvePassage($quorumPassage, 'member:2')->isApproved());
        $this->assertTrue($service->approvePassage($autoPassage, 'member:2')->isApproved());
    }

    public function testRejectPassageMarksItRejectedWithAReason(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(), 'opportunity', 99, 'member:7');

        $rejected = $service->rejectPassage($passage, 'member:12', 'missing SOW');

        $this->assertTrue($rejected->isRejected());
        $this->assertSame('missing SOW', $rejected->getRejectedReason());
        $this->assertSame('member:12', $rejected->getApprovedBy());
    }

    public function testRejectPassageDoesNotGuardAgainstSelfRejection(): void
    {
        // Matches GatePassageService::rejectPassage() and GateServiceInterface's own contract:
        // D9's self-approval rule only ever binds approving, not rejecting.
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(), 'opportunity', 99, 'member:7');

        $rejected = $service->rejectPassage($passage, 'member:7', 'changed my mind');

        $this->assertTrue($rejected->isRejected());
    }

    public function testWaiveGateThrowsWhenTheGateIsNotWaivable(): void
    {
        $service = new InMemoryGateService();

        $this->expectException(NonWaivableGateException::class);

        $service->waiveGate($this->gate(waivable: false), 'opportunity', 99, 'member:1', 'emergency override');
    }

    public function testWaiveGateCreatesAnApprovedSelfResolvedPassage(): void
    {
        $service = new InMemoryGateService();

        $passage = $service->waiveGate($this->gate(waivable: true), 'opportunity', 99, 'member:1', 'emergency override');

        $this->assertTrue($passage->isWaiver());
        $this->assertTrue($passage->isApproved());
        $this->assertSame('member:1', $passage->getRequestedBy());
        $this->assertSame('member:1', $passage->getApprovedBy());
        $this->assertSame('emergency override', $passage->getWaiverJustification());
        $this->assertSame([$passage], $service->getApprovedPassagesForEntity('opportunity', 99));
    }

    public function testGetApprovedPassagesForEntityOnlyReturnsApprovedOnesForThatEntityMostRecentFirst(): void
    {
        $service = new InMemoryGateService();
        $gate = $this->gate();

        $first = $service->requestPassage($gate, 'opportunity', 99, 'member:7');
        $service->approvePassage($first, 'member:12');

        $second = $service->requestPassage($gate, 'opportunity', 99, 'member:8');
        $service->approvePassage($second, 'member:13');

        $stillPending = $service->requestPassage($gate, 'opportunity', 99, 'member:9');

        // Different entity id — must not show up in the results below.
        $otherEntity = $service->requestPassage($gate, 'opportunity', 100, 'member:10');
        $service->approvePassage($otherEntity, 'member:14');

        $results = $service->getApprovedPassagesForEntity('opportunity', 99);

        $this->assertCount(2, $results);
        $this->assertSame($second, $results[0], 'most recent approval must come first');
        $this->assertSame($first, $results[1]);
    }

    public function testGetApprovedPassagesForEntityReturnsEmptyWhenNoneAreApproved(): void
    {
        $service = new InMemoryGateService();
        $service->requestPassage($this->gate(), 'opportunity', 42, 'member:1');

        $this->assertSame([], $service->getApprovedPassagesForEntity('opportunity', 42));
    }

    /**
     * Un pase con plazo vencido pasa a EXPIRED, y eso es un HECHO, no una comparacion.
     *
     * Q-P19-B comparo este sistema con el del agente en siete dimensiones. Coincidieron en dos, y las
     * dos eran ausencias: ninguno caducaba. Un pase abierto para siempre deja el proceso detenido sin
     * que nadie lo declare detenido.
     */
    public function testAPassagePastItsDeadlineBecomesExpired(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(), 'opportunity', 99, 'member:7');
        $passage->setExpiresAt(new \DateTime('2026-08-01 10:00:00'));

        $vencio = $service->expireIfDue($passage, new \DateTimeImmutable('2026-08-01 10:00:01'));

        $this->assertTrue($vencio);
        $this->assertSame(GatePassageStatus::EXPIRED, $passage->getStatus());
        $this->assertNotNull($passage->getResolvedAt());
    }

    /**
     * Vencido NO es rechazado, y la diferencia se sostiene en los datos.
     *
     * Rechazar es una decision: alguien miro y dijo que no. Vencer es lo contrario — nadie miro.
     * Meterlos en el mismo estado haria que un silencio se leyera como un juicio, y quien audite
     * manana no podria distinguir "lo negaron" de "se les paso".
     */
    public function testAnExpiredPassageIsNotARejectionAndCarriesNoJudge(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(), 'opportunity', 99, 'member:7');
        $passage->setExpiresAt(new \DateTime('2026-08-01 10:00:00'));
        $service->expireIfDue($passage, new \DateTimeImmutable('2026-08-01 11:00:00'));

        $this->assertNotSame(GatePassageStatus::REJECTED, $passage->getStatus());
        $this->assertNull($passage->getApprovedBy(), 'nadie aprobo ni rechazo');
        $this->assertNull($passage->getRejectedReason(), 'un silencio no tiene motivo');
        $this->assertTrue($passage->getStatus()->isFinal(), 'pero ya no cambia');
    }

    /** Antes del plazo no pasa nada, y llamarlo mil veces no cambia nada. */
    public function testBeforeTheDeadlineNothingHappens(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(), 'opportunity', 99, 'member:7');
        $passage->setExpiresAt(new \DateTime('2026-08-01 10:00:00'));

        for ($i = 0; $i < 3; ++$i) {
            $this->assertFalse($service->expireIfDue($passage, new \DateTimeImmutable('2026-08-01 09:59:59')));
        }

        $this->assertSame(GatePassageStatus::REQUESTED, $passage->getStatus());
    }

    /** Sin plazo, espera indefinidamente — que es lo que hacia antes y a veces es lo correcto. */
    public function testAPassageWithNoDeadlineNeverExpires(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(), 'opportunity', 99, 'member:7');

        $this->assertFalse($service->expireIfDue($passage, new \DateTimeImmutable('2099-01-01 00:00:00')));
        $this->assertSame(GatePassageStatus::REQUESTED, $passage->getStatus());
    }

    /**
     * Un pase YA RESUELTO no se vence encima: aprobado tarde sigue aprobado.
     *
     * Es lo que impide que la caducidad reescriba una decision que alguien si tomo. El pase es
     * append-only y un estado final es permanente.
     */
    public function testAnAlreadyResolvedPassageIsNotOverwrittenByExpiry(): void
    {
        $service = new InMemoryGateService();
        $passage = $service->requestPassage($this->gate(), 'opportunity', 99, 'member:7');
        $passage->setExpiresAt(new \DateTime('2026-08-01 10:00:00'));
        $service->approvePassage($passage, 'member:12');

        $this->assertFalse($service->expireIfDue($passage, new \DateTimeImmutable('2026-08-01 11:00:00')));
        $this->assertSame(GatePassageStatus::APPROVED, $passage->getStatus());
    }
}
