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
 * StateDefinition entity - Define los estados posibles para una maquina de estados.
 *
 * Configuracion data-driven de los estados del pipeline. Cada dominio
 * (opportunity, project) tiene su propio conjunto de estados ordenados,
 * con marcadores de estado inicial y terminal.
 */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_state_definitions')]
#[ORM\Index(name: 'idx_statedef_domain', columns: ['domain'])]
#[ORM\Index(name: 'idx_statedef_code', columns: ['domain', 'code'])]
#[ORM\Index(name: 'idx_statedef_initial', columns: ['domain', 'is_initial'])]
#[ORM\UniqueConstraint(name: 'unique_state_domain_code', columns: ['domain', 'code'])]
#[ORM\HasLifecycleCallbacks]
class StateDefinition
{
    // =========================================================================
    // PROPERTIES - IDENTIFICATION
    // =========================================================================

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    /**
     * Dominio al que pertenece el estado (opportunity, project).
     */
    #[ORM\Column(type: 'string', length: 30)]
    private string $domain;

    /**
     * Codigo unico del estado dentro del dominio (ej: 'lead', 'qualified').
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $code;

    /**
     * Etiqueta legible para mostrar en UI.
     */
    #[ORM\Column(type: 'string', length: 100)]
    private string $label;

    /**
     * Descripcion opcional del estado.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $description = null;

    // =========================================================================
    // PROPERTIES - ORDERING & FLAGS
    // =========================================================================

    /**
     * Orden de visualizacion en el pipeline.
     */
    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    /**
     * Indica si es el estado inicial del dominio.
     */
    #[ORM\Column(name: 'is_initial', type: 'boolean')]
    private bool $isInitial = false;

    /**
     * Indica si es un estado terminal (fin del pipeline).
     */
    #[ORM\Column(name: 'is_terminal', type: 'boolean')]
    private bool $isTerminal = false;

    // =========================================================================
    // PROPERTIES - PRESENTATION
    // =========================================================================

    /**
     * Color para representacion visual en UI.
     */
    #[ORM\Column(type: 'string', length: 30)]
    private string $color = 'gray';

    /**
     * Metadata adicional del estado (JSON).
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
    #[ORM\OneToMany(targetEntity: TransitionDefinition::class, mappedBy: 'fromState', cascade: ['persist'])]
    private Collection $transitionsFrom;

    /** @var Collection<int, TransitionDefinition> */
    #[ORM\OneToMany(targetEntity: TransitionDefinition::class, mappedBy: 'toState', cascade: ['persist'])]
    private Collection $transitionsTo;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
        $this->transitionsFrom = new ArrayCollection();
        $this->transitionsTo = new ArrayCollection();
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    // =========================================================================
    // GETTERS - ORDERING & FLAGS
    // =========================================================================

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isInitial(): bool
    {
        return $this->isInitial;
    }

    public function isTerminal(): bool
    {
        return $this->isTerminal;
    }

    // =========================================================================
    // GETTERS - PRESENTATION
    // =========================================================================

    public function getColor(): string
    {
        return $this->color;
    }

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
    public function getTransitionsFrom(): Collection
    {
        return $this->transitionsFrom;
    }

    /**
     * @return Collection<int, TransitionDefinition>
     */
    public function getTransitionsTo(): Collection
    {
        return $this->transitionsTo;
    }

    // =========================================================================
    // SETTERS - IDENTIFICATION
    // =========================================================================

    /**
     * Sets the domain this state belongs to (e.g. "opportunity", "project").
     */
    public function setDomain(string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    /**
     * Sets the state's unique code within its domain.
     */
    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    /**
     * Sets the state's human-readable label.
     */
    public function setLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    /**
     * Sets the state's optional description.
     */
    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    // =========================================================================
    // SETTERS - ORDERING & FLAGS
    // =========================================================================

    /**
     * Sets the state's display sort order within the pipeline.
     */
    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    /**
     * Sets whether this is the domain's initial state.
     */
    public function setIsInitial(bool $isInitial): self
    {
        $this->isInitial = $isInitial;
        return $this;
    }

    /**
     * Sets whether this is a terminal state (end of the pipeline).
     */
    public function setIsTerminal(bool $isTerminal): self
    {
        $this->isTerminal = $isTerminal;
        return $this;
    }

    // =========================================================================
    // SETTERS - PRESENTATION
    // =========================================================================

    /**
     * Sets the state's display color.
     */
    public function setColor(string $color): self
    {
        $this->color = $color;
        return $this;
    }

    /**
     * Sets additional metadata for the state.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    // =========================================================================
    // COLLECTION METHODS
    // =========================================================================

    /**
     * Adds a transition originating from this state, wiring the inverse side back.
     */
    public function addTransitionFrom(TransitionDefinition $transition): self
    {
        if (!$this->transitionsFrom->contains($transition)) {
            $this->transitionsFrom->add($transition);
            $transition->setFromState($this);
        }
        return $this;
    }

    /**
     * Removes a transition originating from this state, clearing the inverse side.
     */
    public function removeTransitionFrom(TransitionDefinition $transition): self
    {
        if ($this->transitionsFrom->removeElement($transition)) {
            if ($transition->getFromState() === $this) {
                $transition->setFromState(null); // @phpstan-ignore argument.type
            }
        }
        return $this;
    }

    /**
     * Adds a transition arriving at this state, wiring the inverse side back.
     */
    public function addTransitionTo(TransitionDefinition $transition): self
    {
        if (!$this->transitionsTo->contains($transition)) {
            $this->transitionsTo->add($transition);
            $transition->setToState($this);
        }
        return $this;
    }

    /**
     * Removes a transition arriving at this state, clearing the inverse side.
     */
    public function removeTransitionTo(TransitionDefinition $transition): self
    {
        if ($this->transitionsTo->removeElement($transition)) {
            if ($transition->getToState() === $this) {
                $transition->setToState(null); // @phpstan-ignore argument.type
            }
        }
        return $this;
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
            'description' => $this->description,
            'sort_order' => $this->sortOrder,
            'is_initial' => $this->isInitial,
            'is_terminal' => $this->isTerminal,
            'color' => $this->color,
            'metadata' => $this->metadata,
            'transitions_from_count' => $this->transitionsFrom->count(),
            'transitions_to_count' => $this->transitionsTo->count(),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
