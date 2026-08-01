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

namespace Milpa\Workflow\Entities;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Milpa\Workflow\Enums\GatePassageStatus;
use Milpa\Support\UuidGenerator;

/**
 * GatePassage entity - Registro append-only de solicitudes/aprobaciones de gates.
 *
 * Cada registro representa una solicitud para pasar un gate del pipeline.
 * NUNCA se hace UPDATE ni DELETE. Solo INSERT. Una vez creado, el pasaje
 * se resuelve (approve/reject) una sola vez y queda inmutable.
 *
 * Uses polymorphic model: entity_type + entity_id instead of direct FK.
 *
 * CONSTRAINT CRITICO: requestedBy != approvedBy
 * (prohibicion de auto-aprobacion, enforced a nivel de servicio).
 *
 * `requestedBy`/`approvedBy` are opaque principal strings (e.g. "member:42"), matching
 * {@see Evidence::getUploadedBy()} — the engine stores them verbatim and never resolves
 * them to an entity; the consuming product owns identity (D9).
 */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_gate_passages')]
#[ORM\Index(name: 'idx_gp_gate', columns: ['gate_definition_id'])]
#[ORM\Index(name: 'idx_gp_entity', columns: ['entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_gp_status', columns: ['status'])]
#[ORM\Index(name: 'idx_gp_requested', columns: ['requested_by'])]
#[ORM\Index(name: 'idx_gp_approved', columns: ['approved_by'])]
#[ORM\Index(name: 'idx_gp_created', columns: ['created_at'])]
class GatePassage
{
    use UuidGenerator;

    // =========================================================================
    // PROPERTIES - IDENTIFICATION
    // =========================================================================

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    // =========================================================================
    // PROPERTIES - STATUS
    // =========================================================================

    /**
     * Estado del pasaje (requested, approved, rejected).
     */
    #[ORM\Column(name: 'status', type: 'string', length: 20)]
    private string $status = 'requested';

    // =========================================================================
    // PROPERTIES - NOTES & JUSTIFICATION
    // =========================================================================

    /**
     * Notas del solicitante al crear la solicitud.
     */
    #[ORM\Column(name: 'requested_notes', type: 'text', nullable: true)]
    private ?string $requestedNotes = null;

    /**
     * Notas del aprobador al aprobar.
     */
    #[ORM\Column(name: 'approved_notes', type: 'text', nullable: true)]
    private ?string $approvedNotes = null;

    /**
     * Razon del rechazo.
     */
    #[ORM\Column(name: 'rejected_reason', type: 'text', nullable: true)]
    private ?string $rejectedReason = null;

    /**
     * Indica si el gate fue dispensado (waived) sin cumplir requisitos.
     */
    #[ORM\Column(name: 'is_waiver', type: 'boolean')]
    private bool $isWaiver = false;

    /**
     * Justificacion del waiver (obligatorio si isWaiver = true).
     */
    #[ORM\Column(name: 'waiver_justification', type: 'text', nullable: true)]
    private ?string $waiverJustification = null;

    // =========================================================================
    // PROPERTIES - METADATA
    // =========================================================================

    /**
     * Datos adicionales en formato JSON.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    // =========================================================================
    // PROPERTIES - TIMESTAMPS
    // =========================================================================

    /**
     * Fecha de la solicitud.
     */
    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private DateTime $createdAt;

    /**
     * Fecha de aprobacion/rechazo (null mientras esta pendiente).
     */
    #[ORM\Column(name: 'resolved_at', type: 'datetime', nullable: true)]
    private ?DateTime $resolvedAt = null;

    /**
     * Cuándo se cierra la ventana para decidir, o `null` si no hay plazo.
     *
     * `null` es «espera indefinidamente», que es lo que hacía este paquete antes de que esto
     * existiera y a veces es lo correcto. Lo que no podía pasar era que NO SE PUDIERA poner plazo:
     * un pase abierto para siempre deja el proceso detenido sin que nadie lo declare detenido
     * (Q-P19-B).
     *
     * Columna nueva y anulable: los pases que ya existen quedan sin plazo, que es exactamente lo que
     * eran.
     *
     * ── OJO AL INSTALAR: ESTE PAQUETE NO PUEDE MIGRAR TU BASE ───────────────────────────────────
     *
     * `milpa/workflow` declara sus tablas y **no tiene forma de enviar cambios de esquema**. Si tu app
     * ya tenía `workflow_gate_passages` de una versión anterior, agrega la columna antes de usar esta:
     *
     *     ALTER TABLE workflow_gate_passages ADD COLUMN expires_at DATETIME NULL DEFAULT NULL;
     *
     * El hueco está en el tablero como `packages-can-ship-schema`, y hasta que exista el mecanismo
     * esta línea es la única advertencia que hay.
     */
    #[ORM\Column(name: 'expires_at', type: 'datetime', nullable: true)]
    private ?DateTime $expiresAt = null;

    // =========================================================================
    // RELATIONS
    // =========================================================================

    /**
     * Definicion del gate que se solicita pasar.
     */
    #[ORM\ManyToOne(targetEntity: GateDefinition::class)]
    #[ORM\JoinColumn(name: 'gate_definition_id', referencedColumnName: 'id', nullable: false)]
    private GateDefinition $gateDefinition;

    /**
     * Polymorphic entity type (e.g. 'opportunity', 'project').
     */
    #[ORM\Column(name: 'entity_type', type: 'string', length: 50)]
    private string $entityType = 'opportunity';

    /**
     * Polymorphic entity ID.
     */
    #[ORM\Column(name: 'entity_id', type: 'integer')]
    private int $entityId;

    /**
     * Opaque principal that requested the passage (e.g. "member:42"). Never resolved to an
     * entity by the engine (D9) — mirrors {@see Evidence::getUploadedBy()}.
     */
    #[ORM\Column(name: 'requested_by', type: 'string', length: 255)]
    private string $requestedBy;

    /**
     * Opaque principal that approved/rejected the passage (null until resolved). Never
     * resolved to an entity by the engine (D9).
     * CONSTRAINT: No puede ser el mismo que requestedBy (self-approval prohibition).
     */
    #[ORM\Column(name: 'approved_by', type: 'string', length: 255, nullable: true)]
    private ?string $approvedBy = null;

    /** @var Collection<int, Evidence> */
    #[ORM\OneToMany(targetEntity: Evidence::class, mappedBy: 'gatePassage', cascade: ['persist'])]
    private Collection $evidences;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct()
    {
        $this->uuid = self::generateUuid();
        $this->createdAt = new DateTime();
        $this->evidences = new ArrayCollection();
    }

    // =========================================================================
    // GETTERS - IDENTIFICATION
    // =========================================================================

    public function getId(): int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    // =========================================================================
    // GETTERS - STATUS
    // =========================================================================

    /**
     * Obtiene el estado como enum GatePassageStatus.
     */
    public function getStatus(): GatePassageStatus
    {
        return GatePassageStatus::from($this->status);
    }

    public function getStatusValue(): string
    {
        return $this->status;
    }

    // =========================================================================
    // GETTERS - NOTES & JUSTIFICATION
    // =========================================================================

    public function getRequestedNotes(): ?string
    {
        return $this->requestedNotes;
    }

    public function getApprovedNotes(): ?string
    {
        return $this->approvedNotes;
    }

    public function getRejectedReason(): ?string
    {
        return $this->rejectedReason;
    }

    public function isWaiver(): bool
    {
        return $this->isWaiver;
    }

    public function getWaiverJustification(): ?string
    {
        return $this->waiverJustification;
    }

    // =========================================================================
    // GETTERS - METADATA
    // =========================================================================

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    // =========================================================================
    // GETTERS - TIMESTAMPS
    // =========================================================================

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?DateTime
    {
        return $this->resolvedAt;
    }

    // =========================================================================
    // GETTERS - RELATIONS
    // =========================================================================

    public function getGateDefinition(): GateDefinition
    {
        return $this->gateDefinition;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function getRequestedBy(): string
    {
        return $this->requestedBy;
    }

    public function getApprovedBy(): ?string
    {
        return $this->approvedBy;
    }

    /**
     * @return Collection<int, Evidence>
     */
    public function getEvidences(): Collection
    {
        return $this->evidences;
    }

    // =========================================================================
    // SETTERS - STATUS
    // =========================================================================

    /**
     * Sets the passage's status.
     */
    public function setStatus(GatePassageStatus $status): self
    {
        $this->status = $status->value;
        return $this;
    }

    public function getExpiresAt(): ?DateTime
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?DateTime $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    // =========================================================================
    // SETTERS - NOTES & JUSTIFICATION
    // =========================================================================

    /**
     * Sets the requester's notes at the time the passage was requested.
     */
    public function setRequestedNotes(?string $requestedNotes): self
    {
        $this->requestedNotes = $requestedNotes;
        return $this;
    }

    /**
     * Sets the approver's notes at the time the passage was approved.
     */
    public function setApprovedNotes(?string $approvedNotes): self
    {
        $this->approvedNotes = $approvedNotes;
        return $this;
    }

    /**
     * Sets the reason the passage was rejected.
     */
    public function setRejectedReason(?string $rejectedReason): self
    {
        $this->rejectedReason = $rejectedReason;
        return $this;
    }

    /**
     * Sets whether this passage was waived instead of meeting its requirements.
     */
    public function setIsWaiver(bool $isWaiver): self
    {
        $this->isWaiver = $isWaiver;
        return $this;
    }

    /**
     * Sets the justification for the waiver (required when {@see self::isWaiver()} is true).
     */
    public function setWaiverJustification(?string $waiverJustification): self
    {
        $this->waiverJustification = $waiverJustification;
        return $this;
    }

    // =========================================================================
    // SETTERS - METADATA
    // =========================================================================

    /**
     * Sets additional metadata for the passage.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    // =========================================================================
    // SETTERS - RELATIONS
    // =========================================================================

    /**
     * Sets the gate definition this passage requests to pass.
     */
    public function setGateDefinition(GateDefinition $gateDefinition): self
    {
        $this->gateDefinition = $gateDefinition;
        return $this;
    }

    /**
     * Sets the polymorphic entity type this passage belongs to (e.g. "opportunity").
     */
    public function setEntityType(string $entityType): self
    {
        $this->entityType = $entityType;
        return $this;
    }

    /**
     * Sets the polymorphic entity ID this passage belongs to.
     */
    public function setEntityId(int $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    /**
     * Sets the opaque principal that requested the passage (e.g. "member:42"); never
     * resolved to an entity by the engine — the consuming product owns identity (D9).
     */
    public function setRequestedBy(string $requestedBy): self
    {
        $this->requestedBy = $requestedBy;
        return $this;
    }

    /**
     * Sets the opaque principal that approved or rejected the passage; never resolved
     * to an entity by the engine (D9). Must not equal {@see self::getRequestedBy()}.
     */
    public function setApprovedBy(?string $approvedBy): self
    {
        $this->approvedBy = $approvedBy;
        return $this;
    }

    // =========================================================================
    // COLLECTION METHODS
    // =========================================================================

    /**
     * Adds a piece of evidence to this passage, wiring the inverse side back.
     */
    public function addEvidence(Evidence $evidence): self
    {
        if (!$this->evidences->contains($evidence)) {
            $this->evidences->add($evidence);
            $evidence->setGatePassage($this);
        }
        return $this;
    }

    /**
     * Removes a piece of evidence from this passage.
     */
    public function removeEvidence(Evidence $evidence): self
    {
        $this->evidences->removeElement($evidence);
        return $this;
    }

    // =========================================================================
    // DOMAIN METHODS
    // =========================================================================

    /**
     * Aprueba el pasaje del gate.
     * CONSTRAINT: El aprobador no puede ser el mismo que el solicitante.
     */
    public function approve(string $approverId, ?string $notes = null): self
    {
        $this->status = GatePassageStatus::APPROVED->value;
        $this->approvedBy = $approverId;
        $this->resolvedAt = new DateTime();
        $this->approvedNotes = $notes;
        return $this;
    }

    /**
     * Rechaza el pasaje del gate.
     * CONSTRAINT: El aprobador no puede ser el mismo que el solicitante.
     */
    public function reject(string $approverId, string $reason): self
    {
        $this->status = GatePassageStatus::REJECTED->value;
        $this->approvedBy = $approverId;
        $this->resolvedAt = new DateTime();
        $this->rejectedReason = $reason;
        return $this;
    }

    /**
     * Vence el pasaje: nadie decidio dentro de la ventana.
     *
     * Hermana de {@see self::approve()} y {@see self::reject()} y deliberadamente distinta de las
     * dos: no lleva `approvedBy` porque NADIE aprobo, y no lleva `rejectedReason` porque nadie
     * rechazo. Un silencio no es un juicio.
     *
     * No la llames desde cualquier lado: la unica puerta es
     * {@see \Milpa\Workflow\Contracts\GateServiceInterface::expireIfDue()}, que es quien decide
     * SI vencio. Esta solo escribe el hecho.
     */
    public function expire(): self
    {
        $this->status = GatePassageStatus::EXPIRED->value;
        $this->resolvedAt = new DateTime();
        return $this;
    }

    /**
     * Verifica si el pasaje esta pendiente de resolucion.
     */
    public function isPending(): bool
    {
        return $this->status === GatePassageStatus::REQUESTED->value;
    }

    /**
     * Verifica si el pasaje fue aprobado.
     */
    public function isApproved(): bool
    {
        return $this->status === GatePassageStatus::APPROVED->value;
    }

    /**
     * Verifica si el pasaje fue rechazado.
     */
    public function isRejected(): bool
    {
        return $this->status === GatePassageStatus::REJECTED->value;
    }

    // =========================================================================
    // SERIALIZATION
    // =========================================================================

    /**
     * Converts the entity to an array for API responses.
     *
     * NOTA: requestedBy != approvedBy (self-approval prohibition, enforced at service
     * level). Both are opaque principal strings (D9) — see the class docblock.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status,
            'status_label' => $this->getStatus()->label(),
            'gate_definition_id' => $this->gateDefinition->getId(),
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'requested_by' => $this->requestedBy,
            'approved_by' => $this->approvedBy,
            'requested_notes' => $this->requestedNotes,
            'approved_notes' => $this->approvedNotes,
            'rejected_reason' => $this->rejectedReason,
            'is_waiver' => $this->isWaiver,
            'waiver_justification' => $this->waiverJustification,
            'metadata' => $this->metadata,
            'evidences_count' => $this->evidences->count(),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'resolved_at' => $this->resolvedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
