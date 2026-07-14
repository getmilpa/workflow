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

use Milpa\Workflow\Entities\GateDefinition;

/**
 * Interface que define cómo se evalúa un gate.
 *
 * Los implementadores leen la configuración del GateDefinition
 * (tipo, requerimientos, evidencias, campos) y validan contra el
 * TransitionContext proporcionado.
 *
 * Ejemplos de implementaciones:
 * - StandardGateEvaluator: Evaluador genérico que valida campos y evidencias
 * - RoleBasedGateEvaluator: Evaluador que valida permisos por rol
 * - CustomGateEvaluator: Evaluador para lógica de negocio específica
 */
interface GateEvaluatorInterface
{
    /**
     * Evalúa si un gate específico permite la transición.
     *
     * Lee la configuración del GateDefinition y valida contra el contexto:
     * - Si el gate requiere campos (required_fields), verifica que estén en context->fieldValues
     * - Si el gate requiere evidencias (required_evidence), verifica que estén en context->evidenceIds
     * - Si el gate ya fue pasado (context->gatePassages), puede retornar pass()
     * - Si el gate fue exceptuado (context->metadata['waived_gate']), puede retornar waived()
     *
     * @param GateDefinition    $gateDefinition Definición del gate desde BD
     * @param TransitionContext $context        Contexto con datos del actor, entidad y evaluación
     *
     * @return GateResult Resultado de la evaluación (pass/fail/waived)
     */
    public function evaluate(
        GateDefinition $gateDefinition,
        TransitionContext $context
    ): GateResult;

    /**
     * Retorna el nombre identificador del evaluador.
     *
     * Útil para logging y debugging.
     * Ejemplo: 'standard', 'role_based', 'budget_approval'
     */
    public function getName(): string;
}
