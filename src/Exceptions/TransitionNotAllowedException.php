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

namespace Milpa\Workflow\Exceptions;

/**
 * Thrown by {@see \Milpa\Workflow\Contracts\StateMachineInterface::transition()} when a
 * requested state transition is not defined, disabled, or blocked by a failed gate —
 * carries the from/to states, domain, and (when a gate blocked it) the missing
 * fields/evidence a caller needs to retry successfully.
 */
class TransitionNotAllowedException extends \RuntimeException
{
    private string $fromState;
    private string $toState;
    private string $domain;
    private ?string $gateCode;
    /** @var array<string> */
    private array $missingFields;
    /** @var array<string> */
    private array $missingEvidence;

    /**
     * @param string        $fromState       Estado origen
     * @param string        $toState         Estado destino
     * @param string        $domain          Domain (opportunity/project)
     * @param string|null   $gateCode        Código del gate que bloqueó
     * @param array<string> $missingFields   Campos faltantes
     * @param array<string> $missingEvidence Evidencias faltantes
     * @param string|null   $reason          Razón adicional
     */
    public function __construct(
        string $fromState,
        string $toState,
        string $domain,
        ?string $gateCode = null,
        array $missingFields = [],
        array $missingEvidence = [],
        ?string $reason = null
    ) {
        $this->fromState = $fromState;
        $this->toState = $toState;
        $this->domain = $domain;
        $this->gateCode = $gateCode;
        $this->missingFields = $missingFields;
        $this->missingEvidence = $missingEvidence;

        $message = $reason ?? "Transición no permitida: {$fromState} -> {$toState} en dominio '{$domain}'";
        if ($gateCode !== null) {
            $message .= " [gate: {$gateCode}]";
        }
        parent::__construct($message);
    }

    public function getFromState(): string
    {
        return $this->fromState;
    }

    public function getToState(): string
    {
        return $this->toState;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getGateCode(): ?string
    {
        return $this->gateCode;
    }

    /** @return array<string> */
    public function getMissingFields(): array
    {
        return $this->missingFields;
    }

    /** @return array<string> */
    public function getMissingEvidence(): array
    {
        return $this->missingEvidence;
    }
}
