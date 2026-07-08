<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\StateMachine;

use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;
use Milpa\Workflow\Enums\ApprovalPolicy;
use Milpa\Workflow\Exceptions\TransitionNotAllowedException;
use Milpa\Workflow\Gates\DefaultGateEvaluator;
use Milpa\Workflow\StateMachine\DataDrivenStateMachine;
use Milpa\Workflow\StateMachine\TransitionContext;
use Milpa\Workflow\Tests\Support\InMemoryEntityManagerFactory;

/**
 * Repository-backed coverage of {@see DataDrivenStateMachine}: `findState()`/`findTransition()`
 * genuinely need a working `EntityManagerInterface` (`findBy`/`findOneBy` against real
 * mapped associations), so this suite runs against an in-memory SQLite `EntityManager`
 * ({@see InMemoryEntityManagerFactory}) rather than a hand-mocked one — see that class's
 * docblock for why. No live MySQL, no network.
 */
final class DataDrivenStateMachineTest extends TestCase
{
    private EntityManager $em;
    private DataDrivenStateMachine $stateMachine;

    protected function setUp(): void
    {
        $this->em = InMemoryEntityManagerFactory::create();
        $this->stateMachine = new DataDrivenStateMachine($this->em, new DefaultGateEvaluator());
    }

    /**
     * Seeds `lead` -[ungated]-> `qualified` -[gated: requires 'sales' role, blocks on
     * failure]-> `won` for domain 'opportunity'.
     */
    private function seedOpportunityDomain(): void
    {
        $lead = (new StateDefinition())->setDomain('opportunity')->setCode('lead')->setLabel('Lead')->setSortOrder(1)->setIsInitial(true);
        $qualified = (new StateDefinition())->setDomain('opportunity')->setCode('qualified')->setLabel('Qualified')->setSortOrder(2);
        $won = (new StateDefinition())->setDomain('opportunity')->setCode('won')->setLabel('Won')->setSortOrder(3)->setIsTerminal(true);

        $ungated = (new TransitionDefinition())->setDomain('opportunity')->setCode('lead_to_qualified')
            ->setFromState($lead)->setToState($qualified)->setEnabled(true);

        $gate = (new GateDefinition())->setDomain('opportunity')->setCode('sales_gate')->setName('Sales Gate')
            ->setRequesterRole('sales')->setApproverRole('manager')->setApprovalPolicy(ApprovalPolicy::SINGLE)
            ->setFailureAction('block');

        $gated = (new TransitionDefinition())->setDomain('opportunity')->setCode('qualified_to_won')
            ->setFromState($qualified)->setToState($won)->setEnabled(true);
        $gated->addGateDefinition($gate);

        $disabled = (new TransitionDefinition())->setDomain('opportunity')->setCode('lead_to_won_disabled')
            ->setFromState($lead)->setToState($won)->setEnabled(false);

        foreach ([$lead, $qualified, $won, $ungated, $gate, $gated, $disabled] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();
    }

    public function testCanTransitionPassesWhenTheTransitionHasNoGates(): void
    {
        $this->seedOpportunityDomain();

        $result = $this->stateMachine->canTransition('opportunity', 'lead', 'qualified', new TransitionContext());

        $this->assertTrue($result->isPassed());
    }

    public function testCanTransitionFailsWhenNoTransitionIsDefined(): void
    {
        $this->seedOpportunityDomain();

        $result = $this->stateMachine->canTransition('opportunity', 'won', 'lead', new TransitionContext());

        $this->assertFalse($result->isPassed());
        $this->assertSame('TRANSITION_NOT_FOUND', $result->gateCode);
    }

    public function testCanTransitionFailsWhenTheTransitionIsDisabled(): void
    {
        $this->seedOpportunityDomain();

        $result = $this->stateMachine->canTransition('opportunity', 'lead', 'won', new TransitionContext());

        $this->assertFalse($result->isPassed());
        $this->assertSame('TRANSITION_DISABLED', $result->gateCode);
    }

    public function testCanTransitionBlocksWhenTheGatedRoleDoesNotMatchTheActor(): void
    {
        $this->seedOpportunityDomain();

        $result = $this->stateMachine->canTransition(
            'opportunity',
            'qualified',
            'won',
            new TransitionContext(actorRole: 'ops'),
        );

        $this->assertFalse($result->isPassed());
        $this->assertSame('sales_gate', $result->gateCode);
    }

    public function testCanTransitionPassesWhenTheActorHoldsTheRequiredRole(): void
    {
        $this->seedOpportunityDomain();

        $result = $this->stateMachine->canTransition(
            'opportunity',
            'qualified',
            'won',
            new TransitionContext(actorRole: 'sales'),
        );

        $this->assertTrue($result->isPassed());
    }

    public function testTransitionReturnsTheDestinationStateOnSuccess(): void
    {
        $this->seedOpportunityDomain();

        $newState = $this->stateMachine->transition('opportunity', 'lead', 'qualified', new TransitionContext());

        $this->assertSame('qualified', $newState->getCode());
    }

    public function testTransitionThrowsWhenNotAllowed(): void
    {
        $this->seedOpportunityDomain();

        $this->expectException(TransitionNotAllowedException::class);

        $this->stateMachine->transition('opportunity', 'qualified', 'won', new TransitionContext(actorRole: 'ops'));
    }

    public function testGetAvailableTransitionsListsEveryDefinedTransitionFromTheCurrentState(): void
    {
        $this->seedOpportunityDomain();

        $available = $this->stateMachine->getAvailableTransitions('opportunity', 'lead', new TransitionContext());

        $this->assertCount(1, $available);
        $this->assertSame('lead_to_qualified', $available[0]['transition']->getCode());
        $this->assertTrue($available[0]['result']->isPassed());
    }

    public function testFindStateReturnsNullForAnUnknownCode(): void
    {
        $this->seedOpportunityDomain();

        $this->assertNull($this->stateMachine->findState('opportunity', 'nope'));
        $this->assertNotNull($this->stateMachine->findState('opportunity', 'lead'));
    }

    public function testGetStatesReturnsThemOrderedBySortOrder(): void
    {
        $this->seedOpportunityDomain();

        $codes = array_map(
            static fn (StateDefinition $s): string => $s->getCode(),
            $this->stateMachine->getStates('opportunity'),
        );

        $this->assertSame(['lead', 'qualified', 'won'], $codes);
    }

    public function testGetInitialStateReturnsTheStateFlaggedAsInitial(): void
    {
        $this->seedOpportunityDomain();

        $initial = $this->stateMachine->getInitialState('opportunity');

        $this->assertNotNull($initial);
        $this->assertSame('lead', $initial->getCode());
    }
}
