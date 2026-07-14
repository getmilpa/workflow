<?php

/**
 * This file is part of milpa/workflow — the ORM-backed state machine of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/workflow
 */

declare(strict_types=1);

namespace Milpa\Workflow\Contracts;

use Milpa\Workflow\StateMachine\TransitionContext;
use Milpa\Workflow\StateMachine\GateResult;
use Milpa\Workflow\Entities\StateDefinition;

/**
 * Reads states, transitions and gates for a domain from data-driven definitions
 * ({@see \Milpa\Workflow\Entities\StateDefinition}, {@see \Milpa\Workflow\Entities\TransitionDefinition},
 * {@see \Milpa\Workflow\Entities\GateDefinition}) instead of a hardcoded transition graph.
 */
interface StateMachineInterface
{
    /**
     * Checks whether a transition from `$fromState` to `$toState` is currently possible for
     * `$domain`, evaluating every gate configured on the transition against `$context`.
     */
    public function canTransition(string $domain, string $fromState, string $toState, TransitionContext $context): GateResult;

    /**
     * Returns every transition defined from `$currentState`, each paired with the
     * {@see GateResult} of evaluating it against `$context`.
     *
     * @return array<int, array{transition: \Milpa\Workflow\Entities\TransitionDefinition, result: GateResult}>
     */
    public function getAvailableTransitions(string $domain, string $currentState, TransitionContext $context): array;

    /**
     * Executes a transition from `$fromState` to `$toState`, throwing when it is not allowed.
     *
     * @throws \Milpa\Workflow\Exceptions\TransitionNotAllowedException when the transition is
     *                                                                  undefined, disabled, or blocked by a failed gate
     */
    public function transition(string $domain, string $fromState, string $toState, TransitionContext $context): StateDefinition;

    /**
     * Looks up a single state by domain and code.
     */
    public function findState(string $domain, string $stateCode): ?StateDefinition;

    /**
     * Returns every state defined for a domain, ordered for display.
     *
     * @return StateDefinition[]
     */
    public function getStates(string $domain): array;

    /**
     * Returns the domain's initial state, or null if none is configured.
     */
    public function getInitialState(string $domain): ?StateDefinition;
}
