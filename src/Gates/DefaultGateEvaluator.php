<?php

declare(strict_types=1);

namespace Milpa\Workflow\Gates;

use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\StateMachine\GateEvaluatorInterface;
use Milpa\Workflow\StateMachine\GateResult;
use Milpa\Workflow\StateMachine\TransitionContext;

/**
 * DefaultGateEvaluator - Implementación por defecto de GateEvaluatorInterface.
 *
 * Evalúa gates basándose en la configuración de GateDefinition:
 * - Valida que el actor tenga el rol requerido (requester_role)
 * - Valida campos requeridos (required_fields)
 * - Valida evidencias requeridas (required_evidence_types)
 * - Verifica que exista un gate passage aprobado
 */
class DefaultGateEvaluator implements GateEvaluatorInterface
{
    /**
     * Evalúa si el gate permite la transición.
     *
     * @param GateDefinition    $gateDefinition Definición del gate desde BD
     * @param TransitionContext $context        Contexto de la transición
     *
     * @return GateResult Resultado de la evaluación
     */
    public function evaluate(GateDefinition $gateDefinition, TransitionContext $context): GateResult
    {
        // 1. Verificar si el gate fue exceptuado (waived)
        if ($context->isGateWaived($gateDefinition->getCode())) {
            $justification = $context->getWaiverJustification() ?? 'No justification provided';
            return GateResult::waived($gateDefinition->getCode(), $justification);
        }

        // 2. Verificar gate passage aprobado PRIMERO
        // Si el gate ya tiene un passage aprobado, está satisfecho para cualquier actor
        $requiredEvidence = $gateDefinition->getRequiredEvidenceTypes() ?? [];
        if (!empty($requiredEvidence)) {
            if (in_array($gateDefinition->getId(), $context->gatePassages, true)) {
                return GateResult::pass();
            }
        }

        // 3. Verificar que el actor tiene el rol requerido para solicitar
        // El rol 'admin' puede ejecutar cualquier gate (bypass de rol)
        if ($context->actorRole !== null && $context->actorRole !== 'admin') {
            $requesterRole = $gateDefinition->getRequesterRole();

            // Solo validar si hay un requester_role configurado
            if (!empty($requesterRole) && $context->actorRole !== $requesterRole) {
                return GateResult::fail(
                    sprintf(
                        "Gate '%s' requiere rol '%s' pero el actor tiene rol '%s'",
                        $gateDefinition->getCode(),
                        $requesterRole,
                        $context->actorRole
                    ),
                    $gateDefinition->getCode()
                );
            }
        }

        // 4. Verificar campos requeridos
        $requiredFields = $gateDefinition->getRequiredFields() ?? [];
        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (!isset($context->fieldValues[$field]) || empty($context->fieldValues[$field])) {
                $missingFields[] = $field;
            }
        }

        // 5. Verificar evidencia requerida
        $missingEvidence = [];

        // Si hay tipos de evidencia requeridos, verificar que haya evidencia adjunta
        // (la validación detallada se hace en el servicio de nivel superior)
        if (!empty($requiredEvidence) && empty($context->evidenceIds)) {
            $missingEvidence = $requiredEvidence;
        }

        // 6. Si hay campos o evidencia faltante, el gate no pasa
        if (!empty($missingFields) || !empty($missingEvidence)) {
            $messageParts = [];

            if (!empty($missingFields)) {
                $messageParts[] = sprintf(
                    "campos faltantes: [%s]",
                    implode(', ', $missingFields)
                );
            }

            if (!empty($missingEvidence)) {
                $messageParts[] = sprintf(
                    "evidencia faltante: [%s]",
                    implode(', ', $missingEvidence)
                );
            }

            return GateResult::fail(
                sprintf(
                    "Gate '%s' requiere %s",
                    $gateDefinition->getCode(),
                    implode(' y ', $messageParts)
                ),
                $gateDefinition->getCode(),
                $missingFields,
                $missingEvidence
            );
        }

        // 7. Gate requiere evidencia pero no tiene passage aprobado
        if (!empty($requiredEvidence)) {
            return GateResult::fail(
                sprintf(
                    "Gate '%s' no tiene un passage aprobado",
                    $gateDefinition->getCode()
                ),
                $gateDefinition->getCode(),
                [],
                $requiredEvidence
            );
        }

        // 8. Todas las validaciones pasaron
        return GateResult::pass();
    }

    /**
     * Retorna el nombre del evaluador.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'DefaultGateEvaluator';
    }
}
