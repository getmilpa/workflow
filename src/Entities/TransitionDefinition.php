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

/**
 * TransitionDefinition entity - Define transiciones validas entre estados.
 *
 * Configuracion data-driven de la maquina de estados. Cada transicion
 * conecta un fromState con un toState y puede requerir uno o mas gates.
 */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_transition_definitions')]
#[ORM\Index(name: 'idx_transdef_domain', columns: ['domain'])]
#[ORM\Index(name: 'idx_transdef_code', columns: ['domain', 'code'])]
#[ORM\Index(name: 'idx_transdef_from', columns: ['from_state_id'])]
#[ORM\Index(name: 'idx_transdef_to', columns: ['to_state_id'])]
#[ORM\Index(name: 'idx_transdef_enabled', columns: ['enabled'])]
#[ORM\UniqueConstraint(name: 'unique_trans_code', columns: ['domain', 'code'])]
#[ORM\HasLifecycleCallbacks]
class TransitionDefinition
{
    // =========================================================================
    // PROPERTIES - IDENTIFICATION
    // =========================================================================

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    /**
     * Dominio de la transicion: 'opportunity' o 'project'.
     */
    #[ORM\Column(type: 'string', length: 30)]
    private string $domain;

    /**
     * Codigo unico de la transicion (ej: 'lead_to_qualified').
     */
    #[ORM\Column(type: 'string', length: 100)]
    private string $code;

    /**
     * Etiqueta legible de la transicion.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $label = null;

    // =========================================================================
    // PROPERTIES - AUTHORIZATION
    // =========================================================================

    /**
     * Rol minimo requerido para ejecutar la transicion. Valor del AgencyRole enum.
     */
    #[ORM\Column(name: 'required_role', type: 'string', length: 30, nullable: true)]
    private ?string $requiredRole = null;

    // =========================================================================
    // PROPERTIES - METADATA
    // =========================================================================

    /**
     * Indica si la transición está habilitada.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $enabled = true;

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

    #[ORM\ManyToOne(targetEntity: StateDefinition::class)]
    #[ORM\JoinColumn(name: 'from_state_id', referencedColumnName: 'id', nullable: false)]
    private StateDefinition $fromState;

    #[ORM\ManyToOne(targetEntity: StateDefinition::class)]
    #[ORM\JoinColumn(name: 'to_state_id', referencedColumnName: 'id', nullable: false)]
    private StateDefinition $toState;

    /** @var Collection<int, GateDefinition> */
    #[ORM\ManyToMany(targetEntity: GateDefinition::class, inversedBy: 'transitions')]
    #[ORM\JoinTable(
        name: 'workflow_transition_gates',
        joinColumns: [new ORM\JoinColumn(name: 'transition_id', referencedColumnName: 'id')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'gate_id', referencedColumnName: 'id')]
    )]
    private Collection $gateDefinitions;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
        $this->gateDefinitions = new ArrayCollection();
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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    // =========================================================================
    // GETTERS - AUTHORIZATION
    // =========================================================================

    public function getRequiredRole(): ?string
    {
        return $this->requiredRole;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
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

    public function getFromState(): StateDefinition
    {
        return $this->fromState;
    }

    public function getToState(): StateDefinition
    {
        return $this->toState;
    }

    /**
     * @return Collection<int, GateDefinition>
     */
    public function getGateDefinitions(): Collection
    {
        return $this->gateDefinitions;
    }

    // =========================================================================
    // SETTERS - IDENTIFICATION
    // =========================================================================

    /**
     * Sets the domain this transition belongs to (e.g. "opportunity", "project").
     */
    public function setDomain(string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    /**
     * Sets the transition's unique code within its domain.
     */
    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    /**
     * Sets the transition's human-readable label.
     */
    public function setLabel(?string $label): self
    {
        $this->label = $label;
        return $this;
    }

    // =========================================================================
    // SETTERS - AUTHORIZATION
    // =========================================================================

    /**
     * Sets the minimum role required to execute this transition.
     */
    public function setRequiredRole(?string $requiredRole): self
    {
        $this->requiredRole = $requiredRole;
        return $this;
    }

    /**
     * Sets whether this transition is enabled.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    // =========================================================================
    // SETTERS - METADATA
    // =========================================================================

    /**
     * Sets additional metadata for the transition.
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
     * Sets the state this transition originates from.
     */
    public function setFromState(StateDefinition $fromState): self
    {
        $this->fromState = $fromState;
        return $this;
    }

    /**
     * Sets the state this transition arrives at.
     */
    public function setToState(StateDefinition $toState): self
    {
        $this->toState = $toState;
        return $this;
    }

    // =========================================================================
    // COLLECTION METHODS
    // =========================================================================

    /**
     * Adds a gate that must pass before this transition is allowed.
     */
    public function addGateDefinition(GateDefinition $gateDefinition): self
    {
        if (!$this->gateDefinitions->contains($gateDefinition)) {
            $this->gateDefinitions->add($gateDefinition);
        }
        return $this;
    }

    /**
     * Removes a gate from this transition's requirements.
     */
    public function removeGateDefinition(GateDefinition $gateDefinition): self
    {
        $this->gateDefinitions->removeElement($gateDefinition);
        return $this;
    }

    // =========================================================================
    // DOMAIN METHODS
    // =========================================================================

    /**
     * Checks if this transition has any gates configured.
     */
    public function hasGates(): bool
    {
        return !$this->gateDefinitions->isEmpty();
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
            'label' => $this->label,
            'required_role' => $this->requiredRole,
            'metadata' => $this->metadata,
            'from_state' => $this->fromState->toArray(),
            'to_state' => $this->toState->toArray(),
            'gate_definitions' => array_map(
                fn (GateDefinition $gate) => [
                    'id' => $gate->getId(),
                    'code' => $gate->getCode(),
                    'name' => $gate->getName(),
                ],
                $this->gateDefinitions->toArray()
            ),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
