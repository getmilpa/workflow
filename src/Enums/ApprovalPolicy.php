<?php

declare(strict_types=1);

namespace Milpa\Workflow\Enums;

/**
 * How many approvals a {@see \Milpa\Workflow\Entities\GatePassage} needs before a
 * {@see \Milpa\Workflow\Entities\GateDefinition} is considered passed.
 */
enum ApprovalPolicy: string
{
    case SINGLE = 'single';
    case DUAL = 'dual';
    case QUORUM = 'quorum';
    case AUTO = 'auto';

    /**
     * Human-readable label for this policy.
     */
    public function label(): string
    {
        return match($this) {
            self::SINGLE => 'Aprobación Simple',
            self::DUAL => 'Aprobación Dual',
            self::QUORUM => 'Quorum',
            self::AUTO => 'Auto-aprobación con Evidencia',
        };
    }

    /**
     * Number of distinct approvals this policy requires; 0 means the count is
     * either dynamic (QUORUM) or determined by evidence submission alone (AUTO).
     */
    public function requiredApprovals(): int
    {
        return match($this) {
            self::SINGLE => 1,
            self::DUAL => 2,
            self::QUORUM => 0, // Dinámico
            self::AUTO => 0, // Se aprueba al enviar evidencia
        };
    }
}
