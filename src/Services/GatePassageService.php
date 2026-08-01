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

use Doctrine\ORM\EntityManagerInterface;
use Milpa\Interfaces\Observability\AuditLoggerInterface;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\GatePassage;
use Milpa\Workflow\Enums\GatePassageStatus;
use Milpa\Workflow\Exceptions\NonWaivableGateException;
use Milpa\Workflow\Exceptions\SelfApprovalException;
use Milpa\Workflow\Contracts\GateServiceInterface;

/**
 * Doctrine-backed {@see GateServiceInterface} implementation: persists append-only
 * {@see \Milpa\Workflow\Entities\GatePassage} rows and enforces the anti-self-approval
 * constraint (D9) at the service layer, since it is not expressible as a column constraint
 * on an opaque principal string.
 */
class GatePassageService implements GateServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?AuditLoggerInterface $auditLogger = null
    ) {
    }

    /**
     * Requests a new gate passage for the given gate and polymorphic entity, persisting it
     * with {@see \Milpa\Workflow\Enums\GatePassageStatus::REQUESTED} and auditing the request.
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

        $this->em->persist($passage);
        $this->em->flush();

        $this->auditLogger?->log(
            entityType: 'GatePassage',
            entityId: (string) $passage->getId(),
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
     * Approves a pending gate passage and audits the approval.
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

        $passage->approve($approverId, $notes);

        $this->em->persist($passage);
        $this->em->flush();

        $this->auditLogger?->log(
            entityType: 'GatePassage',
            entityId: (string) $passage->getId(),
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
     * Rejects a pending gate passage with a reason and audits the rejection.
     */
    public function rejectPassage(
        GatePassage $passage,
        string $rejectorId,
        string $reason
    ): GatePassage {
        $passage->reject($rejectorId, $reason);

        $this->em->persist($passage);
        $this->em->flush();

        $this->auditLogger?->log(
            entityType: 'GatePassage',
            entityId: (string) $passage->getId(),
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
     * Returns every approved gate passage for the given polymorphic entity, most recent first.
     *
     * @return array<int, GatePassage>
     */
    public function getApprovedPassagesForEntity(string $entityType, int $entityId): array
    {
        return $this->em->getRepository(GatePassage::class)
            ->createQueryBuilder('gp')
            ->where('gp.entityType = :entityType')
            ->andWhere('gp.entityId = :entityId')
            ->andWhere('gp.status = :status')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->setParameter('status', GatePassageStatus::APPROVED->value)
            ->orderBy('gp.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
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

        $this->em->persist($passage);
        $this->em->flush();

        $this->auditLogger?->log(
            entityType: 'GatePassage',
            entityId: (string) $passage->getId(),
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
     * EL ÚNICO SITIO donde un pase pasa de pendiente a vencido. Ver
     * {@see GateServiceInterface::expireIfDue()} para por qué es un hecho y no una comparación.
     */
    public function expireIfDue(GatePassage $passage, \DateTimeImmutable $now): bool
    {
        $plazo = $passage->getExpiresAt();
        if ($plazo === null || $passage->getStatus()->isFinal()) {
            return false;
        }

        if ($now <= \DateTimeImmutable::createFromInterface($plazo)) {
            return false;
        }

        $passage->expire();

        $this->em->flush();

        $this->auditLogger?->log(
            entityType: 'GatePassage',
            entityId: (string) $passage->getId(),
            action: 'expired',
            actorUserId: null,
            newValues: [
                'status' => GatePassageStatus::EXPIRED->value,
                'expired_at' => $now->format(\DateTimeInterface::ATOM),
            ],
        );

        return true;
    }
}
