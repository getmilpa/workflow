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

namespace Milpa\Workflow\Enums;

/**
 * Lifecycle status of a {@see \Milpa\Workflow\Entities\GatePassage}.
 */
enum GatePassageStatus: string
{
    case REQUESTED = 'requested';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    /**
     * Human-readable label for this status.
     */
    public function label(): string
    {
        return match($this) {
            self::REQUESTED => 'Solicitado',
            self::APPROVED => 'Aprobado',
            self::REJECTED => 'Rechazado',
        };
    }

    /**
     * True when the passage is resolved (approved or rejected) and will not change again —
     * {@see \Milpa\Workflow\Entities\GatePassage} is append-only, so a final status is permanent.
     */
    public function isFinal(): bool
    {
        return $this === self::APPROVED || $this === self::REJECTED;
    }
}
