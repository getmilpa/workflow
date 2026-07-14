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

namespace Milpa\Workflow\StateMachine;

use Doctrine\ORM\EntityManagerInterface;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Exceptions\TransitionNotAllowedException;
use Milpa\Workflow\Contracts\StateMachineInterface;

/**
 * Máquina de estados DATA-DRIVEN que lee configuración desde BD.
 *
 * A diferencia de una máquina de estados con transiciones hardcodeadas en constantes,
 * esta clase lee estados, transiciones y gates desde las entidades:
 * - StateDefinition: Estados disponibles
 * - TransitionDefinition: Transiciones permitidas entre estados
 * - GateDefinition: Gates que validan cada transición
 *
 * Flujo de evaluación:
 * 1. Buscar TransitionDefinition en BD para domain + fromState + toState
 * 2. Obtener GateDefinitions asociadas a la transición
 * 3. Evaluar cada gate con el GateEvaluator
 * 4. Si algún gate falla y su failureAction es 'block', denegar transición
 */
class DataDrivenStateMachine implements StateMachineInterface
{
    private EntityManagerInterface $em;
    private GateEvaluatorInterface $gateEvaluator;

    public function __construct(
        EntityManagerInterface $em,
        GateEvaluatorInterface $gateEvaluator
    ) {
        $this->em = $em;
        $this->gateEvaluator = $gateEvaluator;
    }

    /**
     * Verifica si una transición es posible.
     *
     * Proceso:
     * 1. Busca TransitionDefinition en BD
     * 2. Si no existe, retorna deny (transición no definida)
     * 3. Si existe, evalúa todos los gates asociados
     * 4. Si algún gate falla y tiene failureAction = 'block', retorna deny
     * 5. Si todos los gates pasan (o fueron exceptuados), retorna pass
     *
     * @param string            $domain    'opportunity' o 'project'
     * @param string            $fromState Código del estado actual
     * @param string            $toState   Código del estado destino
     * @param TransitionContext $context   Contexto de evaluación
     *
     * @return GateResult Resultado de la evaluación
     */
    public function canTransition(
        string $domain,
        string $fromState,
        string $toState,
        TransitionContext $context
    ): GateResult {
        // 1. Buscar la transición en BD
        $transition = $this->findTransition($domain, $fromState, $toState);

        if ($transition === null) {
            return GateResult::fail(
                message: "Transición de '{$fromState}' a '{$toState}' no está definida para domain '{$domain}'",
                gateCode: 'TRANSITION_NOT_FOUND'
            );
        }

        // 2. Si la transición no está habilitada, denegar
        if (!$transition->isEnabled()) {
            return GateResult::fail(
                message: "Transición '{$transition->getCode()}' está deshabilitada",
                gateCode: 'TRANSITION_DISABLED'
            );
        }

        // 3. Obtener gates asociados a la transición
        $gates = $transition->getGateDefinitions();

        // 4. Si no hay gates, permitir transición
        if ($gates->isEmpty()) {
            return GateResult::pass();
        }

        // 5. Evaluar cada gate
        $missingFields = [];
        $missingEvidence = [];
        $failedGates = [];

        foreach ($gates as $gate) {
            /** @var GateDefinition $gate */

            // Evaluar el gate
            $result = $this->gateEvaluator->evaluate($gate, $context);

            // Si el gate no pasó
            if (!$result->isPassed()) {
                // Acumular campos y evidencias faltantes
                $missingFields = array_merge($missingFields, $result->missingFields);
                $missingEvidence = array_merge($missingEvidence, $result->missingEvidence);

                // Si el failureAction es 'block', denegar inmediatamente
                if ($gate->getFailureAction() === 'block') {
                    return GateResult::fail(
                        message: $result->message ?? "Gate '{$gate->getCode()}' no superado",
                        gateCode: $gate->getCode(),
                        missingFields: $result->missingFields,
                        missingEvidence: $result->missingEvidence
                    );
                }

                // Si es 'warn', solo registrar pero continuar
                $failedGates[] = $gate->getCode();
            }
        }

        // 6. Si hay gates que fallaron con 'warn', retornar pass con mensaje
        if (!empty($failedGates)) {
            return GateResult::pass();
        }

        // 7. Todos los gates pasaron
        return GateResult::pass();
    }

    /**
     * Obtiene las transiciones disponibles desde un estado.
     *
     * Consulta BD para encontrar TransitionDefinitions con from_state = currentState
     * y evalúa los gates de cada una para determinar si están disponibles.
     *
     * @param string            $domain       'opportunity' o 'project'
     * @param string            $currentState Código del estado actual
     * @param TransitionContext $context      Contexto de evaluación
     *
     * @return array<int, array{transition: TransitionDefinition, result: GateResult}> Array de ['transition' => TransitionDefinition, 'result' => GateResult]
     */
    public function getAvailableTransitions(
        string $domain,
        string $currentState,
        TransitionContext $context
    ): array {
        // Buscar el estado actual
        $fromState = $this->findState($domain, $currentState);

        if ($fromState === null) {
            return [];
        }

        // Obtener transiciones desde este estado
        $transitionRepo = $this->em->getRepository(TransitionDefinition::class);
        $transitions = $transitionRepo->findBy([
            'domain' => $domain,
            'fromState' => $fromState,
            'enabled' => true,
        ]);

        $available = [];

        foreach ($transitions as $transition) {
            /** @var TransitionDefinition $transition */

            $toStateCode = $transition->getToState()->getCode();

            // Evaluar si la transición es posible
            $result = $this->canTransition($domain, $currentState, $toStateCode, $context);

            $available[] = [
                'transition' => $transition,
                'result' => $result,
            ];
        }

        return $available;
    }

    /**
     * Busca una TransitionDefinition entre dos estados.
     *
     * @param string $domain    'opportunity' o 'project'
     * @param string $fromState Código del estado origen
     * @param string $toState   Código del estado destino
     *
     * @return TransitionDefinition|null La transición encontrada o null
     */
    private function findTransition(
        string $domain,
        string $fromState,
        string $toState
    ): ?TransitionDefinition {
        // Buscar estados por código
        $fromStateEntity = $this->findState($domain, $fromState);
        $toStateEntity = $this->findState($domain, $toState);

        if ($fromStateEntity === null || $toStateEntity === null) {
            return null;
        }

        // Buscar transición
        $repo = $this->em->getRepository(TransitionDefinition::class);
        return $repo->findOneBy([
            'domain' => $domain,
            'fromState' => $fromStateEntity,
            'toState' => $toStateEntity,
        ]);
    }

    /**
     * Busca un StateDefinition por domain y code.
     *
     * @param string $domain    'opportunity' o 'project'
     * @param string $stateCode Código del estado (ej: 'prospecting', 'active')
     *
     * @return StateDefinition|null El estado encontrado o null
     */
    public function findState(string $domain, string $stateCode): ?StateDefinition
    {
        $repo = $this->em->getRepository(StateDefinition::class);
        return $repo->findOneBy([
            'domain' => $domain,
            'code' => $stateCode,
        ]);
    }

    /**
     * Ejecuta una transición si es permitida.
     *
     * Método de conveniencia que combina canTransition() con la lógica
     * de cambio de estado.
     *
     * @param string            $domain    Domain de la entidad
     * @param string            $fromState Estado actual
     * @param string            $toState   Estado destino
     * @param TransitionContext $context   Contexto de evaluación
     *
     * @return StateDefinition El nuevo estado
     *
     * @throws TransitionNotAllowedException Si la transición no es permitida
     */
    public function transition(
        string $domain,
        string $fromState,
        string $toState,
        TransitionContext $context
    ): StateDefinition {
        $result = $this->canTransition($domain, $fromState, $toState, $context);

        if (!$result->isPassed()) {
            throw new TransitionNotAllowedException(
                fromState: $fromState,
                toState: $toState,
                domain: $domain,
                gateCode: $result->gateCode,
                missingFields: $result->missingFields,
                missingEvidence: $result->missingEvidence,
                reason: $result->message
            );
        }

        $newState = $this->findState($domain, $toState);

        if ($newState === null) {
            throw new TransitionNotAllowedException(
                fromState: $fromState,
                toState: $toState,
                domain: $domain,
                reason: "Estado destino '{$toState}' no encontrado"
            );
        }

        return $newState;
    }

    /**
     * Obtiene todos los estados para un domain.
     *
     * @param string $domain 'opportunity' o 'project'
     *
     * @return StateDefinition[] Array de estados
     */
    public function getStates(string $domain): array
    {
        $repo = $this->em->getRepository(StateDefinition::class);
        return $repo->findBy(
            ['domain' => $domain],
            ['sortOrder' => 'ASC']
        );
    }

    /**
     * Obtiene el estado inicial para un domain.
     *
     * @param string $domain 'opportunity' o 'project'
     *
     * @return StateDefinition|null El estado inicial o null
     */
    public function getInitialState(string $domain): ?StateDefinition
    {
        $repo = $this->em->getRepository(StateDefinition::class);
        return $repo->findOneBy([
            'domain' => $domain,
            'isInitial' => true,
        ]);
    }
}
