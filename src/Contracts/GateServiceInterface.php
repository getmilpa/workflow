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

use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\GatePassage;

/**
 * `$requesterId`/`$approverId`/`$rejectorId`/`$waiverId` are opaque principal strings
 * (e.g. "member:42"), mirroring {@see \Milpa\Workflow\Entities\Evidence::getUploadedBy()} —
 * the engine stores them verbatim and never resolves them to an entity; the consuming
 * product owns identity (D9).
 */
interface GateServiceInterface
{
    /**
     * Requests a new gate passage for the given gate and polymorphic entity.
     *
     * @param array<string, mixed>|null $fieldValues
     */
    public function requestPassage(GateDefinition $gate, string $entityType, int $entityId, string $requesterId, ?array $fieldValues = null): GatePassage;

    /**
     * Approves a pending gate passage. Implementations must reject the call (typically via
     * `SelfApprovalException`) when `$approverId` equals the passage's requester (D9).
     */
    public function approvePassage(GatePassage $passage, string $approverId, ?string $notes = null): GatePassage;

    /**
     * Rejects a pending gate passage with a reason.
     */
    public function rejectPassage(GatePassage $passage, string $rejectorId, string $reason): GatePassage;

    /**
     * Approves a gate passage without requiring its normal evidence/field requirements,
     * recording the waiver and its justification. Implementations must reject the call
     * (typically via `NonWaivableGateException`) when the gate is not waivable.
     */
    public function waiveGate(GateDefinition $gate, string $entityType, int $entityId, string $waiverId, string $justification): GatePassage;

    /**
     * Returns every approved gate passage for the given polymorphic entity.
     *
     * @return array<int, GatePassage>
     */
    public function getApprovedPassagesForEntity(string $entityType, int $entityId): array;

    /**
     * EL ÚNICO SITIO donde un pase pasa de pendiente a vencido.
     *
     * ── POR QUÉ ES UN MÉTODO Y NO UNA COMPARACIÓN QUE CADA UNO HAGA ─────────────────────────────
     *
     * Porque «vencido» tiene que ser **un hecho**, y un hecho lo produce alguien en un instante
     * concreto. Si cada componente comparara `expiresAt` con su propio reloj, dos de ellos podrían
     * contestar distinto sobre el mismo pase, y ninguno dejaría rastro de cuándo se notó.
     *
     * Los demás **observan el resultado**: leen `getStatus() === GatePassageStatus::EXPIRED`. No lo
     * recalculan, no lo reinterpretan, no lo repiten. Es la condición que Rod puso a esta mitad, y su
     * razón es de historia: este repositorio ya encontró cuatro comparadores de identidad de
     * capacidad que no coincidían, y una cardinalidad que decidía el orden de carga. Dos sitios que
     * deciden lo mismo divergen; la única pregunta es cuándo.
     *
     * ── POR QUÉ RECIBE EL INSTANTE ──────────────────────────────────────────────────────────────
     *
     * Para que se pueda probar sin esperar, y para que quien lo llame decida qué reloj vale. Un
     * método que consulta la hora por su cuenta obliga a que las pruebas duerman.
     *
     * No hace nada si el pase ya está resuelto, si no tiene plazo, o si el plazo no ha vencido:
     * devuelve `false` y nada cambia. Se puede llamar en cada lectura sin ensuciar nada.
     *
     * Es la contraparte de {@see \Milpa\Agent\SessionStore::expireIfDue()} en el otro sistema de
     * aprobación — Q-P19-B midió que son cosas
     * distintas en cinco de siete dimensiones, y que **ésta es la única que compartían**: la de no
     * tener ninguna.
     */
    public function expireIfDue(GatePassage $passage, \DateTimeImmutable $now): bool;
}
