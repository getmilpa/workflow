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

namespace Milpa\Workflow\Services;

use Milpa\Interfaces\Observability\AuditLoggerInterface;
use Milpa\Workflow\Contracts\GateServiceInterface;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\GatePassage;
use Milpa\Workflow\Enums\GatePassageStatus;
use Milpa\Workflow\Exceptions\NonWaivableGateException;
use Milpa\Workflow\Exceptions\SelfApprovalException;

/**
 * Non-Doctrine {@see GateServiceInterface} implementation: evaluates gates entirely in process
 * memory, with **no `Doctrine\ORM\EntityManagerInterface` dependency at all** — constructor,
 * properties, everything. It exists for zero-DB / event-sourced consumers (e.g. an
 * `milpa/orchestrator` process replaying its own append-only event log) that cannot construct
 * {@see GatePassageService} because they have no `EntityManagerInterface` to give it, and would
 * otherwise have to hand-roll the D9 self-approval check and the waivability guard themselves
 * (see the orchestrator greenhouse's `HumanGate` for exactly that reimplementation, and its
 * report's "workflow Doctrine-vs-event-sourced finding" section for the full gap writeup).
 *
 * It returns the SAME {@see GatePassage} entity {@see GatePassageService} returns — no parallel
 * VO was needed. `GatePassage`'s constructor and setters are plain PHP (Doctrine's `#[ORM\...]`
 * attributes are metadata read only by an `EntityManager`, never by the entity itself — see
 * `tests/Entities/GatePassageTest.php`'s own docblock for the same observation), so `new
 * GatePassage()` works with zero Doctrine involvement as long as nobody calls `persist()`/
 * `flush()` on it or reads its Doctrine-generated `getId()` (which stays uninitialized and
 * throws if touched — this class never calls it, using {@see GatePassage::getUuid()} instead
 * wherever a stable identifier is needed, e.g. for {@see AuditLoggerInterface::log()}).
 *
 * **Scope of "in memory":** state (the distinct approvers recorded so far per passage, and the
 * approved-passages-per-entity index) lives only for the lifetime of THIS service instance —
 * there is no cross-process, cross-request, or cross-restart durability, by design (that's what
 * "in memory" means). A caller needs to keep the same instance alive across the
 * `requestPassage()`/`approvePassage()` calls it expects to correlate (e.g. as a request-scoped
 * or long-lived singleton); a consumer that needs durable, replayable approval counts across
 * process boundaries (a true event-sourced system) should record each approval as its own event
 * in its own log and either call this service once per resolved decision, or not delegate
 * multi-approval bookkeeping to it at all. See the package README's "Gate services" section.
 *
 * **`ApprovalPolicy` handling:** {@see GatePassageService} stores a gate's `ApprovalPolicy` but
 * never reads it back — one `approvePassage()` call always finalizes the passage, regardless of
 * policy. This class is the first to actually honor it: {@see \Milpa\Workflow\Enums\ApprovalPolicy::DUAL}
 * (the only case with an unambiguous, non-zero `requiredApprovals()`) requires two DISTINCT approver
 * principals — via two separate {@see self::approvePassage()} calls on the same passage — before
 * the passage transitions out of `REQUESTED`; a single call records the approver and returns the
 * still-pending passage. `SINGLE`, `QUORUM`, and `AUTO` all resolve on the first approval: `QUORUM`
 * and `AUTO` report `requiredApprovals() === 0` (a dynamic quorum size / evidence-driven
 * auto-approval, respectively), and since {@see GateDefinition} carries no quorum-size or
 * evidence-submission-count field for this package to read, both fall back to requiring exactly
 * one approval — the same behavior `GatePassageService` already gives every policy today.
 *
 * **Self-approval / waivability parity with `GatePassageService`:** {@see self::approvePassage()}
 * throws {@see SelfApprovalException} when the approver equals the requester, exactly like
 * {@see GateServiceInterface::approvePassage()}'s own contract requires — matching
 * `GatePassageService`, {@see self::rejectPassage()} does NOT carry this guard (D9's own
 * wording, and `GateServiceInterface`'s docblock, only ever bind self-approval to *approving*).
 * {@see self::waiveGate()} throws {@see NonWaivableGateException} when the gate's `isWaivable`
 * flag is false, identically to `GatePassageService::waiveGate()`.
 */
final class InMemoryGateService implements GateServiceInterface
{
    /**
     * Distinct approver principals recorded so far per passage, keyed by
     * `spl_object_id($passage)` — only ever grows past one entry for
     * {@see \Milpa\Workflow\Enums\ApprovalPolicy::DUAL}.
     *
     * @var array<int, list<string>>
     */
    private array $approvers = [];

    /**
     * Approved passages, most-recent-first, keyed by `"{entityType}:{entityId}"` — the in-memory
     * counterpart of {@see GatePassageService::getApprovedPassagesForEntity()}'s query.
     *
     * @var array<string, list<GatePassage>>
     */
    private array $approvedByEntity = [];

    public function __construct(
        private readonly ?AuditLoggerInterface $auditLogger = null
    ) {
    }

    /**
     * Requests a new gate passage for the given gate and polymorphic entity, held only in this
     * service instance's memory (never persisted) and auditing the request.
     *
     * @param array<string, mixed>|null $fieldValues
     */
    public function requestPassage(
        GateDefinition $gate,
        string $entityType,
        int $entityId,
        string $requesterId,
        ?array $fieldValues = null
    ): GatePassage {
        $passage = new GatePassage();
        $passage->setGateDefinition($gate);
        $passage->setEntityType($entityType);
        $passage->setEntityId($entityId);
        $passage->setRequestedBy($requesterId);
        $passage->setStatus(GatePassageStatus::REQUESTED);

        if ($fieldValues !== null) {
            $passage->setMetadata($fieldValues);
        }

        $this->auditLogger?->log(
            entityType: 'GatePassage',
            entityId: $passage->getUuid(),
            action: 'requested',
            actorUserId: $requesterId,
            newValues: [
                'gate_code' => $gate->getCode(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'status' => GatePassageStatus::REQUESTED->value,
            ]
        );

        return $passage;
    }

    /**
     * Records an approval for a pending gate passage and audits it. The passage only leaves
     * `REQUESTED` once enough DISTINCT approvers have called this method to satisfy the gate's
     * `ApprovalPolicy` (see class docblock) — until then it is returned unchanged, still pending.
     *
     * @throws SelfApprovalException when `$approverId` equals the passage's requester (D9)
     */
    public function approvePassage(
        GatePassage $passage,
        string $approverId,
        ?string $notes = null
    ): GatePassage {
        if ($passage->getRequestedBy() === $approverId) {
            throw new SelfApprovalException(
                $approverId,
                $passage->getGateDefinition()->getCode()
            );
        }

        $key = spl_object_id($passage);
        $approvers = $this->approvers[$key] ?? [];
        if (!in_array($approverId, $approvers, true)) {
            $approvers[] = $approverId;
        }
        $this->approvers[$key] = $approvers;

        $this->auditLogger?->log(
            entityType: 'GatePassage',
            entityId: $passage->getUuid(),
            action: 'approval_recorded',
            actorUserId: $approverId,
            newValues: [
                'approvers_so_far' => $approvers,
                'required_approvals' => $this->requiredApprovals($passage->getGateDefinition()),
            ]
        );

        if (count($approvers) < $this->requiredApprovals($passage->getGateDefinition())) {
            return $passage;
        }

        $passage->approve($approverId, $notes);
        $this->recordApproved($passage);

        $this->auditLogger?->log(
            entityType: 'GatePassage',
            entityId: $passage->getUuid(),
            action: 'approved',
            actorUserId: $approverId,
            newValues: [
                'status' => GatePassageStatus::APPROVED->value,
                'approved_notes' => $notes,
            ]
        );

        return $passage;
    }

    /**
     * Rejects a pending gate passage with a reason and audits the rejection. Unlike
     * {@see self::approvePassage()}, this is not guarded against self-rejection — neither is
     * {@see GatePassageService::rejectPassage()}, matching `GateServiceInterface`'s own contract,
     * which binds the D9 self-approval rule to approving only.
     */
    public function rejectPassage(
        GatePassage $passage,
        string $rejectorId,
        string $reason
    ): GatePassage {
        $passage->reject($rejectorId, $reason);

        $this->auditLogger?->log(
            entityType: 'GatePassage',
            entityId: $passage->getUuid(),
            action: 'rejected',
            actorUserId: $rejectorId,
            newValues: [
                'status' => GatePassageStatus::REJECTED->value,
                'rejected_reason' => $reason,
            ]
        );

        return $passage;
    }

    /**
     * Returns every approved gate passage for the given polymorphic entity, most recent first —
     * the in-memory counterpart of {@see GatePassageService::getApprovedPassagesForEntity()}'s
     * `QueryBuilder`. Only passages resolved through THIS service instance are visible.
     *
     * @return array<int, GatePassage>
     */
    public function getApprovedPassagesForEntity(string $entityType, int $entityId): array
    {
        return $this->approvedByEntity[$entityType . ':' . $entityId] ?? [];
    }

    /**
     * Approves a gate passage without requiring its normal evidence/field requirements,
     * recording the waiver and its justification, and audits it.
     *
     * @throws NonWaivableGateException when the gate's `isWaivable` flag is false
     */
    public function waiveGate(
        GateDefinition $gate,
        string $entityType,
        int $entityId,
        string $waiverId,
        string $justification
    ): GatePassage {
        if (!$gate->isWaivable()) {
            throw new NonWaivableGateException($gate->getCode());
        }

        $passage = new GatePassage();
        $passage->setGateDefinition($gate);
        $passage->setEntityType($entityType);
        $passage->setEntityId($entityId);
        $passage->setRequestedBy($waiverId);
        $passage->setStatus(GatePassageStatus::APPROVED);
        $passage->setIsWaiver(true);
        $passage->setWaiverJustification($justification);
        $passage->setApprovedBy($waiverId);

        $this->recordApproved($passage);

        $this->auditLogger?->log(
            entityType: 'GatePassage',
            entityId: $passage->getUuid(),
            action: 'waived',
            actorUserId: $waiverId,
            newValues: [
                'gate_code' => $gate->getCode(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'status' => GatePassageStatus::APPROVED->value,
                'is_waiver' => true,
                'justification' => $justification,
            ]
        );

        return $passage;
    }

    /**
     * Prepends `$passage` to its entity's approved-passages index (most-recent-first, matching
     * `GatePassageService::getApprovedPassagesForEntity()`'s `ORDER BY created_at DESC`).
     */
    private function recordApproved(GatePassage $passage): void
    {
        $key = $passage->getEntityType() . ':' . $passage->getEntityId();
        $this->approvedByEntity[$key] = [$passage, ...$this->approvedByEntity[$key] ?? []];
    }

    /**
     * Number of distinct approvers `$gate`'s `ApprovalPolicy` requires before a passage resolves
     * — see the class docblock's "ApprovalPolicy handling" section for the `QUORUM`/`AUTO`
     * fallback rationale.
     */
    private function requiredApprovals(GateDefinition $gate): int
    {
        $required = $gate->getApprovalPolicy()->requiredApprovals();

        return $required > 0 ? $required : 1;
    }
}
