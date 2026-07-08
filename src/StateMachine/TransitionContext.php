<?php

declare(strict_types=1);

namespace Milpa\Workflow\StateMachine;

/**
 * DTO (Data Transfer Object) con el contexto de una transición.
 *
 * Contiene toda la información necesaria para evaluar si una transición
 * de estado es permitida, incluyendo actor, entidad, gates, evidencias y metadatos.
 */
class TransitionContext
{
    public function __construct(
        // ============================================
        // Actor (quien ejecuta la transición)
        // ============================================
        public readonly ?int $actorId = null,
        public readonly ?string $actorRole = null, // Valor de AgencyRole enum

        // ============================================
        // Entidad que está transicionando
        // ============================================
        public readonly ?int $entityId = null,
        public readonly string $domain = 'opportunity', // 'opportunity' o 'project'

        // ============================================
        // Contexto de evaluación de gates
        // ============================================

        /**
         * IDs de GatePassage ya aprobados.
         * Permite verificar si un gate específico ya fue superado anteriormente.
         *
         * @var array<int> Array de IDs de GatePassage
         */
        public readonly array $gatePassages = [],

        /**
         * IDs de Evidence adjuntas a la entidad.
         * Permite validar si se han subido las evidencias requeridas.
         *
         * @var array<int> Array de IDs de Evidence
         */
        public readonly array $evidenceIds = [],

        /**
         * Valores de campos requeridos.
         * Key-value map para validar si se completaron campos obligatorios.
         *
         * Ejemplo: ['budget' => 50000, 'client_name' => 'ACME Corp']
         *
         * @var array<string, mixed>
         */
        public readonly array $fieldValues = [],

        // ============================================
        // Razón y metadatos adicionales
        // ============================================

        /**
         * Razón textual de la transición.
         * Puede ser usado para logging o justificación.
         */
        public readonly ?string $reason = null,

        /**
         * Metadatos adicionales para contexto específico.
         *
         * Ejemplos:
         * - ['waived_gate' => 'BUDGET_APPROVAL', 'justification' => 'CEO override']
         * - ['ip_address' => '192.168.1.1', 'user_agent' => 'Mozilla...']
         *
         * @var array<string, mixed>
         */
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Verifica si un gate específico fue pasado previamente.
     */
    public function hasPassedGate(int $gatePassageId): bool
    {
        return in_array($gatePassageId, $this->gatePassages, true);
    }

    /**
     * Verifica si una evidencia específica está presente.
     */
    public function hasEvidence(int $evidenceId): bool
    {
        return in_array($evidenceId, $this->evidenceIds, true);
    }

    /**
     * Verifica si un campo tiene valor.
     */
    public function hasFieldValue(string $fieldName): bool
    {
        return array_key_exists($fieldName, $this->fieldValues);
    }

    /**
     * Obtiene el valor de un campo o null si no existe.
     */
    public function getFieldValue(string $fieldName): mixed
    {
        return $this->fieldValues[$fieldName] ?? null;
    }

    /**
     * Verifica si hay un metadato específico.
     */
    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    /**
     * Obtiene un metadato o null si no existe.
     */
    public function getMetadata(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }

    /**
     * Verifica si el contexto indica que un gate fue exceptuado (waived).
     */
    public function isGateWaived(string $gateCode): bool
    {
        return $this->hasMetadata('waived_gate')
            && $this->getMetadata('waived_gate') === $gateCode;
    }

    /**
     * Obtiene la justificación de un gate exceptuado, si existe.
     */
    public function getWaiverJustification(): ?string
    {
        return $this->getMetadata('justification');
    }

    /**
     * Convierte el contexto a array para debugging o logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'actorRole' => $this->actorRole,
            'entityId' => $this->entityId,
            'domain' => $this->domain,
            'gatePassages' => $this->gatePassages,
            'evidenceIds' => $this->evidenceIds,
            'fieldValues' => $this->fieldValues,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
        ];
    }
}
