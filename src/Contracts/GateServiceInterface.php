<?php

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
}
