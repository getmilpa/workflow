<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\Verification;

use PHPUnit\Framework\TestCase;
use Milpa\Enums\VerificationStatus;
use Milpa\ValueObjects\Verification\VerificationContext;
use Milpa\ValueObjects\Verification\VerificationRequest;
use Milpa\Workflow\Contracts\StateMachineInterface;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\StateMachine\GateResult;
use Milpa\Workflow\StateMachine\TransitionContext;
use Milpa\Workflow\Verification\StateMachineVerifier;

/**
 * Ported from the monorepo's `tests/Unit/Verification/StateMachineVerifierTest.php` (T094):
 * exposes the gate machinery as a first-class core `VerifierInterface` — a generic
 * `VerificationRequest`/`VerificationContext` in, a bridged `VerificationResult` out. No
 * Doctrine, no EntityManager — the transition inputs ride in the request payload and the
 * state machine is stubbed here.
 */
final class StateMachineVerifierTest extends TestCase
{
    public function testVerifyBridgesAPassingTransitionToASatisfiedResult(): void
    {
        $verifier = new StateMachineVerifier($this->stateMachine(GateResult::pass()));

        $result = $verifier->verify(
            new VerificationRequest('transition:opportunity', payload: [
                'domain' => 'opportunity', 'fromState' => 'lead', 'toState' => 'qualified',
            ]),
            new VerificationContext(principal: 'user:42'),
        );

        $this->assertSame(VerificationStatus::PASSED, $result->status);
        $this->assertTrue($result->isSatisfied());
        $this->assertSame(StateMachineVerifier::NAME, $result->verifier);
        $this->assertSame('user:42', $result->principal);
    }

    public function testVerifyBridgesABlockedTransitionToAFailedResultWithMissing(): void
    {
        $verifier = new StateMachineVerifier(
            $this->stateMachine(GateResult::fail('needs fit_score', 'qualification_gate', ['fit_score'], [])),
        );

        $result = $verifier->verify(
            new VerificationRequest('transition:opportunity', payload: [
                'domain' => 'opportunity', 'fromState' => 'lead', 'toState' => 'qualified',
            ]),
            new VerificationContext(principal: 'user:42'),
        );

        $this->assertSame(VerificationStatus::FAILED, $result->status);
        $this->assertSame(['fit_score'], $result->missing);
        $this->assertSame('qualification_gate', $result->metadata['gateCode']);
        $this->assertSame(StateMachineVerifier::NAME, $result->verifier);
        $this->assertSame('user:42', $result->principal);
    }

    public function testVerifyMapsThePayloadAndContextIntoTheTransitionContext(): void
    {
        $spy = $this->capturingStateMachine();
        $verifier = new StateMachineVerifier($spy);

        $verifier->verify(
            new VerificationRequest('transition:opportunity', payload: [
                'domain' => 'opportunity', 'fromState' => 'lead', 'toState' => 'qualified',
                'actorId' => 7, 'actorRole' => 'sales', 'entityId' => 99,
                'gatePassages' => [1, 2], 'evidenceIds' => [5],
                'fieldValues' => ['fit_score' => 8],
            ]),
            new VerificationContext(principal: 'user:7', metadata: ['ip' => '1.2.3.4']),
        );

        $ctx = $spy->lastContext;
        $this->assertInstanceOf(TransitionContext::class, $ctx);
        $this->assertSame('opportunity', $ctx->domain);
        $this->assertSame(7, $ctx->actorId);
        $this->assertSame('sales', $ctx->actorRole);
        $this->assertSame(99, $ctx->entityId);
        $this->assertSame([1, 2], $ctx->gatePassages);
        $this->assertSame([5], $ctx->evidenceIds);
        $this->assertSame(['fit_score' => 8], $ctx->fieldValues);
        $this->assertSame(['ip' => '1.2.3.4'], $ctx->metadata);
    }

    public function testVerifyFailsWhenTheTransitionPayloadIsIncomplete(): void
    {
        $verifier = new StateMachineVerifier($this->stateMachine(GateResult::pass()));

        $result = $verifier->verify(
            new VerificationRequest('transition:opportunity', payload: ['domain' => 'opportunity']),
            new VerificationContext(principal: 'user:42'),
        );

        $this->assertSame(VerificationStatus::FAILED, $result->status);
        $this->assertStringContainsString('payload', $result->reason ?? '');
        $this->assertSame(StateMachineVerifier::NAME, $result->verifier);
    }

    // --- stubs ----------------------------------------------------------------

    private function stateMachine(GateResult $result): StateMachineInterface
    {
        return new class ($result) implements StateMachineInterface {
            public function __construct(private GateResult $result)
            {
            }

            public function canTransition(string $domain, string $fromState, string $toState, TransitionContext $context): GateResult
            {
                return $this->result;
            }

            public function getAvailableTransitions(string $domain, string $currentState, TransitionContext $context): array
            {
                return [];
            }

            public function transition(string $domain, string $fromState, string $toState, TransitionContext $context): StateDefinition
            {
                throw new \LogicException('not used in this test');
            }

            public function findState(string $domain, string $stateCode): ?StateDefinition
            {
                return null;
            }

            public function getStates(string $domain): array
            {
                return [];
            }

            public function getInitialState(string $domain): ?StateDefinition
            {
                return null;
            }
        };
    }

    /**
     * A state machine stub that records the last TransitionContext it received.
     */
    private function capturingStateMachine(): StateMachineInterface
    {
        return new class () implements StateMachineInterface {
            public ?TransitionContext $lastContext = null;

            public function canTransition(string $domain, string $fromState, string $toState, TransitionContext $context): GateResult
            {
                $this->lastContext = $context;

                return GateResult::pass();
            }

            public function getAvailableTransitions(string $domain, string $currentState, TransitionContext $context): array
            {
                return [];
            }

            public function transition(string $domain, string $fromState, string $toState, TransitionContext $context): StateDefinition
            {
                throw new \LogicException('not used in this test');
            }

            public function findState(string $domain, string $stateCode): ?StateDefinition
            {
                return null;
            }

            public function getStates(string $domain): array
            {
                return [];
            }

            public function getInitialState(string $domain): ?StateDefinition
            {
                return null;
            }
        };
    }
}
