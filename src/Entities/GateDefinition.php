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
use Milpa\Workflow\Enums\ApprovalPolicy;

/**
 * GateDefinition entity - Define un gate (palanca de control).
 *
 * Configuracion data-driven de los gates del sistema. Cada gate
 * tiene roles requeridos para solicitar y aprobar, politica de aprobacion
 * y tipos de evidencia necesarios.
 */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_gate_definitions')]
#[ORM\Index(name: 'idx_gatedef_domain', columns: ['domain'])]
#[ORM\Index(name: 'idx_gatedef_code', columns: ['domain', 'code'])]
#[ORM\Index(name: 'idx_gatedef_waivable', columns: ['is_waivable'])]
#[ORM\UniqueConstraint(name: 'unique_gate_code', columns: ['domain', 'code'])]
#[ORM\HasLifecycleCallbacks]
class GateDefinition
{
    // =========================================================================
    // PROPERTIES - IDENTIFICATION
    // =========================================================================

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    /**
     * Dominio del gate: 'opportunity' o 'project'.
     */
    #[ORM\Column(type: 'string', length: 30)]
    private string $domain;

    /**
     * Codigo unico del gate (ej: 'qualification_gate', 'sow_signed_gate').
     */
    #[ORM\Column(type: 'string', length: 100)]
    private string $code;

    /**
     * Nombre legible del gate.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /**
     * Descripcion del proposito del gate.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    // =========================================================================
    // PROPERTIES - ROLES & POLICIES
    // =========================================================================

    /**
     * Rol que puede solicitar la apertura del gate. Valor del AgencyRole enum.
     */
    #[ORM\Column(name: 'requester_role', type: 'string', length: 30)]
    private string $requesterRole;

    /**
     * Rol que puede aprobar el gate. Valor del AgencyRole enum.
     */
    #[ORM\Column(name: 'approver_role', type: 'string', length: 30)]
    private string $approverRole;

    /**
     * Politica de aprobacion. Valor del ApprovalPolicy enum.
     */
    #[ORM\Column(name: 'approval_policy', type: 'string', length: 20)]
    private string $approvalPolicy = 'single';

    // =========================================================================
    // PROPERTIES - EVIDENCE & REQUIREMENTS
    // =========================================================================

    /**
     * Tipos de evidencia requeridos. Array de valores del EvidenceType enum.
     *
     * @var array<string>|null
     */
    #[ORM\Column(name: 'required_evidence_types', type: 'json', nullable: true)]
    private ?array $requiredEvidenceTypes = null;

    /**
     * Campos requeridos en el metadata del gate passage.
     *
     * @var array<string>|null
     */
    #[ORM\Column(name: 'required_fields', type: 'json', nullable: true)]
    private ?array $requiredFields = null;

    // =========================================================================
    // PROPERTIES - BEHAVIOR
    // =========================================================================

    /**
     * Indica si el gate puede ser omitido (waived) por roles autorizados.
     */
    #[ORM\Column(name: 'is_waivable', type: 'boolean')]
    private bool $isWaivable = true;

    /**
     * Accion a tomar cuando el gate falla: 'block' o 'warn'.
     */
    #[ORM\Column(name: 'failure_action', type: 'string', length: 50, nullable: true)]
    private ?string $failureAction = null;

    /**
     * Acciones automaticas que se ejecutan al aprobar el gate.
     *
     * @var array<string>|null
     */
    #[ORM\Column(name: 'success_auto_actions', type: 'json', nullable: true)]
    private ?array $successAutoActions = null;

    /**
     * Orden de visualizacion.
     */
    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

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

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private DateTime $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime')]
    private DateTime $updatedAt;

    // =========================================================================
    // RELATIONS
    // =========================================================================

    /** @var Collection<int, TransitionDefinition> */
    #[ORM\ManyToMany(targetEntity: TransitionDefinition::class, mappedBy: 'gateDefinitions')]
    private Collection $transitions;

    /** @var Collection<int, GatePassage> */
    #[ORM\OneToMany(targetEntity: GatePassage::class, mappedBy: 'gateDefinition')]
    private Collection $passages;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
        $this->transitions = new ArrayCollection();
        $this->passages = new ArrayCollection();
    }

    // =========================================================================
    // LIFECYCLE CALLBACKS
    // =========================================================================

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTime();
    }

    // =========================================================================
    // GETTERS - IDENTIFICATION
    // =========================================================================

    public function getId(): int
    {
        return $this->id;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    // =========================================================================
    // GETTERS - ROLES & POLICIES
    // =========================================================================

    public function getRequesterRole(): string
    {
        return $this->requesterRole;
    }

    public function getApproverRole(): string
    {
        return $this->approverRole;
    }

    /**
     * Returns the approval policy as an {@see ApprovalPolicy} enum case.
     */
    public function getApprovalPolicy(): ApprovalPolicy
    {
        return ApprovalPolicy::from($this->approvalPolicy);
    }

    public function getApprovalPolicyValue(): string
    {
        return $this->approvalPolicy;
    }

    // =========================================================================
    // GETTERS - EVIDENCE & REQUIREMENTS
    // =========================================================================

    /**
     * @return array<string>|null
     */
    public function getRequiredEvidenceTypes(): ?array
    {
        return $this->requiredEvidenceTypes;
    }

    /**
     * @return array<string>|null
     */
    public function getRequiredFields(): ?array
    {
        return $this->requiredFields;
    }

    // =========================================================================
    // GETTERS - BEHAVIOR
    // =========================================================================

    public function isWaivable(): bool
    {
        return $this->isWaivable;
    }

    public function getFailureAction(): ?string
    {
        return $this->failureAction;
    }

    /**
     * @return array<string>|null
     */
    public function getSuccessAutoActions(): ?array
    {
        return $this->successAutoActions;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
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

    public function getUpdatedAt(): DateTime
    {
        return $this->updatedAt;
    }

    // =========================================================================
    // GETTERS - RELATIONS
    // =========================================================================

    /**
     * @return Collection<int, TransitionDefinition>
     */
    public function getTransitions(): Collection
    {
        return $this->transitions;
    }

    /**
     * @return Collection<int, GatePassage>
     */
    public function getPassages(): Collection
    {
        return $this->passages;
    }

    // =========================================================================
    // SETTERS - IDENTIFICATION
    // =========================================================================

    /**
     * Sets the domain this gate belongs to (e.g. "opportunity", "project").
     */
    public function setDomain(string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    /**
     * Sets the gate's unique code within its domain.
     */
    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    /**
     * Sets the gate's human-readable name.
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Sets the gate's optional description of its purpose.
     */
    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    // =========================================================================
    // SETTERS - ROLES & POLICIES
    // =========================================================================

    /**
     * Sets the role allowed to request this gate.
     */
    public function setRequesterRole(string $requesterRole): self
    {
        $this->requesterRole = $requesterRole;
        return $this;
    }

    /**
     * Sets the role allowed to approve this gate.
     */
    public function setApproverRole(string $approverRole): self
    {
        $this->approverRole = $approverRole;
        return $this;
    }

    /**
     * Sets the gate's approval policy.
     */
    public function setApprovalPolicy(ApprovalPolicy $approvalPolicy): self
    {
        $this->approvalPolicy = $approvalPolicy->value;
        return $this;
    }

    // =========================================================================
    // SETTERS - EVIDENCE & REQUIREMENTS
    // =========================================================================

    /**
     * Sets the evidence types required to pass this gate.
     *
     * @param array<string>|null $requiredEvidenceTypes
     */
    public function setRequiredEvidenceTypes(?array $requiredEvidenceTypes): self
    {
        $this->requiredEvidenceTypes = $requiredEvidenceTypes;
        return $this;
    }

    /**
     * Sets the fields required in the gate passage's metadata.
     *
     * @param array<string>|null $requiredFields
     */
    public function setRequiredFields(?array $requiredFields): self
    {
        $this->requiredFields = $requiredFields;
        return $this;
    }

    // =========================================================================
    // SETTERS - BEHAVIOR
    // =========================================================================

    /**
     * Sets whether this gate can be waived by an authorized role.
     */
    public function setIsWaivable(bool $isWaivable): self
    {
        $this->isWaivable = $isWaivable;
        return $this;
    }

    /**
     * Sets the action taken when this gate fails ("block" or "warn").
     */
    public function setFailureAction(?string $failureAction): self
    {
        $this->failureAction = $failureAction;
        return $this;
    }

    /**
     * Sets the automatic actions run when this gate is approved.
     *
     * @param array<string>|null $successAutoActions
     */
    public function setSuccessAutoActions(?array $successAutoActions): self
    {
        $this->successAutoActions = $successAutoActions;
        return $this;
    }

    /**
     * Sets the gate's display sort order.
     */
    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    // =========================================================================
    // SETTERS - METADATA
    // =========================================================================

    /**
     * Sets additional metadata for the gate.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    // =========================================================================
    // DOMAIN METHODS
    // =========================================================================

    /**
     * Checks if the failure action blocks the transition.
     */
    public function blocksOnFailure(): bool
    {
        return $this->failureAction === 'block';
    }

    // =========================================================================
    // SERIALIZATION
    // =========================================================================

    /**
     * Converts the entity to an array for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'requester_role' => $this->requesterRole,
            'approver_role' => $this->approverRole,
            'approval_policy' => $this->approvalPolicy,
            'approval_policy_label' => $this->getApprovalPolicy()->label(),
            'required_evidence_types' => $this->requiredEvidenceTypes,
            'required_fields' => $this->requiredFields,
            'is_waivable' => $this->isWaivable,
            'failure_action' => $this->failureAction,
            'success_auto_actions' => $this->successAutoActions,
            'sort_order' => $this->sortOrder,
            'metadata' => $this->metadata,
            'transitions_count' => $this->transitions->count(),
            'passages_count' => $this->passages->count(),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
