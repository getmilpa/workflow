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
     * Se cerró la ventana para decidir, y nadie decidió.
     *
     * ── NO ES «rechazado», Y LA DIFERENCIA NO ES DE MATIZ ───────────────────────────────────────
     *
     * Rechazar es una decisión: alguien miró y dijo que no, y `rejectedReason` dice por qué. Esto es
     * lo contrario — **nadie miró**. Meterlos en el mismo estado haría que un silencio se leyera como
     * un juicio, y quien audite mañana no podría distinguir «lo negaron» de «se les pasó».
     *
     * ── ES UN HECHO, NO UNA COMPARACIÓN DE FECHAS ───────────────────────────────────────────────
     *
     * Un pase está vencido cuando **este estado** lo dice, no cuando alguien compara `expiresAt` con
     * el reloj. Debe existir un único sitio que haga la transición —{@see
     * \Milpa\Workflow\Contracts\GateServiceInterface::expireIfDue()}— y todos los demás
     * componentes sólo observan el resultado: no lo recalculan, no lo reinterpretan, no lo repiten.
     *
     * La regla es de Rod, y su razón es de historia: este repositorio ya encontró cuatro
     * comparadores de identidad de capacidad que no coincidían, y una cardinalidad que decidía el
     * orden de carga. Dos sitios que deciden lo mismo divergen; la única pregunta es cuándo.
     */
    case EXPIRED = 'expired';

    /**
     * Human-readable label for this status.
     */
    public function label(): string
    {
        return match($this) {
            self::REQUESTED => 'Solicitado',
            self::APPROVED => 'Aprobado',
            self::REJECTED => 'Rechazado',
            self::EXPIRED => 'Vencido sin respuesta',
        };
    }

    /**
     * True when the passage is resolved (approved or rejected) and will not change again —
     * {@see \Milpa\Workflow\Entities\GatePassage} is append-only, so a final status is permanent.
     */
    public function isFinal(): bool
    {
        return $this === self::APPROVED || $this === self::REJECTED || $this === self::EXPIRED;
    }
}
