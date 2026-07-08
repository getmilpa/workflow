<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\Gates;

use PHPUnit\Framework\TestCase;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Gates\DefaultGateEvaluator;
use Milpa\Workflow\StateMachine\TransitionContext;
use Milpa\Workflow\Tests\Support\EntityIdSetter;

/**
 * Pure logic coverage of {@see DefaultGateEvaluator} — no Doctrine, no EntityManager.
 * `GateDefinition` is built with `new` + setters; Doctrine's `#[ORM\...]` attributes are
 * metadata only and impose nothing on plain object construction.
 */
final class DefaultGateEvaluatorTest extends TestCase
{
    private function evaluator(): DefaultGateEvaluator
    {
        return new DefaultGateEvaluator();
    }

    public function testPassesWhenGateHasNoRequirements(): void
    {
        $gate = (new GateDefinition())->setCode('open_gate')->setRequesterRole('sales');

        $result = $this->evaluator()->evaluate($gate, new TransitionContext(actorRole: 'sales'));

        $this->assertTrue($result->isPassed());
    }

    public function testWaivedWhenContextMarksTheGateWaived(): void
    {
        $gate = (new GateDefinition())->setCode('budget_gate');
        $context = new TransitionContext(metadata: ['waived_gate' => 'budget_gate', 'justification' => 'CEO override']);

        $result = $this->evaluator()->evaluate($gate, $context);

        $this->assertTrue($result->isPassed());
        $this->assertSame('budget_gate', $result->gateCode);
        $this->assertStringContainsString('CEO override', $result->message ?? '');
    }

    public function testFailsWhenActorRoleDoesNotMatchTheRequesterRole(): void
    {
        $gate = (new GateDefinition())->setCode('qualification_gate')->setRequesterRole('sales');
        $context = new TransitionContext(actorRole: 'ops');

        $result = $this->evaluator()->evaluate($gate, $context);

        $this->assertFalse($result->isPassed());
        $this->assertSame('qualification_gate', $result->gateCode);
    }

    public function testAdminRoleBypassesTheRequesterRoleCheck(): void
    {
        $gate = (new GateDefinition())->setCode('qualification_gate')->setRequesterRole('sales');
        $context = new TransitionContext(actorRole: 'admin');

        $result = $this->evaluator()->evaluate($gate, $context);

        $this->assertTrue($result->isPassed());
    }

    public function testFailsWithMissingFieldsWhenRequiredFieldsAreAbsent(): void
    {
        $gate = (new GateDefinition())
            ->setCode('qualification_gate')
            ->setRequesterRole('')
            ->setRequiredFields(['fit_score', 'budget']);

        $result = $this->evaluator()->evaluate($gate, new TransitionContext(fieldValues: ['fit_score' => 8]));

        $this->assertFalse($result->isPassed());
        $this->assertSame(['budget'], $result->missingFields);
    }

    public function testPassesOnceAllRequiredFieldsAreFilled(): void
    {
        $gate = (new GateDefinition())
            ->setCode('qualification_gate')
            ->setRequesterRole('')
            ->setRequiredFields(['fit_score']);

        $result = $this->evaluator()->evaluate($gate, new TransitionContext(fieldValues: ['fit_score' => 8]));

        $this->assertTrue($result->isPassed());
    }

    public function testFailsWithMissingEvidenceWhenNoEvidenceIdsAreAttached(): void
    {
        $gate = (new GateDefinition())
            ->setCode('sow_signed_gate')
            ->setRequesterRole('')
            ->setRequiredEvidenceTypes(['sow_signed']);
        EntityIdSetter::set($gate, 1);

        $result = $this->evaluator()->evaluate($gate, new TransitionContext(evidenceIds: []));

        $this->assertFalse($result->isPassed());
        $this->assertSame(['sow_signed'], $result->missingEvidence);
    }

    public function testFailsWhenEvidenceIsAttachedButNoPassageIsApprovedYet(): void
    {
        $gate = (new GateDefinition())
            ->setCode('sow_signed_gate')
            ->setRequesterRole('')
            ->setRequiredEvidenceTypes(['sow_signed']);
        EntityIdSetter::set($gate, 1);

        // Evidence is present, but this gate's id is not in the approved-passages list yet.
        $result = $this->evaluator()->evaluate($gate, new TransitionContext(evidenceIds: [5], gatePassages: [999]));

        $this->assertFalse($result->isPassed());
        $this->assertStringContainsString('no tiene un passage aprobado', $result->message ?? '');
    }

    public function testPassesWhenAnApprovedPassageForThisGateIdIsAlreadyOnTheContext(): void
    {
        $gate = (new GateDefinition())
            ->setCode('sow_signed_gate')
            ->setRequesterRole('')
            ->setRequiredEvidenceTypes(['sow_signed']);
        EntityIdSetter::set($gate, 42);

        // The gate's own id (42) appearing in gatePassages short-circuits to pass, regardless
        // of role/fields — it means this gate was already satisfied for the entity.
        $result = $this->evaluator()->evaluate($gate, new TransitionContext(gatePassages: [42]));

        $this->assertTrue($result->isPassed());
    }
}
