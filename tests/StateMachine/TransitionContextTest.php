<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\StateMachine;

use PHPUnit\Framework\TestCase;
use Milpa\Workflow\StateMachine\TransitionContext;

/**
 * Pure value-object coverage of {@see TransitionContext} — no Doctrine.
 */
final class TransitionContextTest extends TestCase
{
    public function testHasPassedGateAndHasEvidenceCheckTheIdLists(): void
    {
        $context = new TransitionContext(gatePassages: [1, 2], evidenceIds: [9]);

        $this->assertTrue($context->hasPassedGate(2));
        $this->assertFalse($context->hasPassedGate(3));
        $this->assertTrue($context->hasEvidence(9));
        $this->assertFalse($context->hasEvidence(10));
    }

    public function testFieldValueAccessors(): void
    {
        $context = new TransitionContext(fieldValues: ['budget' => 50000]);

        $this->assertTrue($context->hasFieldValue('budget'));
        $this->assertSame(50000, $context->getFieldValue('budget'));
        $this->assertFalse($context->hasFieldValue('missing'));
        $this->assertNull($context->getFieldValue('missing'));
    }

    public function testIsGateWaivedOnlyMatchesTheExactGateCode(): void
    {
        $context = new TransitionContext(metadata: ['waived_gate' => 'budget_gate', 'justification' => 'CEO override']);

        $this->assertTrue($context->isGateWaived('budget_gate'));
        $this->assertFalse($context->isGateWaived('other_gate'));
        $this->assertSame('CEO override', $context->getWaiverJustification());
    }

    public function testToArrayReflectsEveryConstructorArgument(): void
    {
        $context = new TransitionContext(
            actorId: 7,
            actorRole: 'sales',
            entityId: 99,
            domain: 'opportunity',
            gatePassages: [1],
            evidenceIds: [2],
            fieldValues: ['budget' => 1],
            reason: 'auto-advance',
            metadata: ['ip' => '1.2.3.4'],
        );

        $this->assertSame([
            'actorId' => 7,
            'actorRole' => 'sales',
            'entityId' => 99,
            'domain' => 'opportunity',
            'gatePassages' => [1],
            'evidenceIds' => [2],
            'fieldValues' => ['budget' => 1],
            'reason' => 'auto-advance',
            'metadata' => ['ip' => '1.2.3.4'],
        ], $context->toArray());
    }
}
