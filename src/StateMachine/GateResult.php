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

namespace Milpa\Workflow\StateMachine;

use Milpa\ValueObjects\Verification\VerificationResult;

/**
 * Domain verdict of a gate evaluation: pass / fail / waived, carrying the fields and
 * evidence whose absence blocked a state transition.
 *
 * This is the workflow engine's domain-specific face of the framework-agnostic core verdict
 * {@see \Milpa\ValueObjects\Verification\VerificationResult} (D8 seam).
 * {@see self::toVerificationResult()} projects this onto the core type and
 * {@see self::fromVerificationResult()} rebuilds it losslessly, so the state machine can
 * speak the core vocabulary at its boundary without changing any of its live consumers.
 */
class GateResult
{
    /**
     * @param list<string> $missingFields
     * @param list<string> $missingEvidence
     */
    private function __construct(
        public readonly bool $passed,
        public readonly ?string $message = null,
        public readonly ?string $gateCode = null,
        public readonly array $missingFields = [],
        public readonly array $missingEvidence = [],
    ) {
    }

    /**
     * Crea un resultado exitoso (gate pasado).
     */
    public static function pass(): self
    {
        return new self(
            passed: true,
            message: null,
            gateCode: null,
            missingFields: [],
            missingEvidence: []
        );
    }

    /**
     * Crea un resultado fallido (gate no pasado).
     *
     * @param string       $message         Mensaje descriptivo del fallo
     * @param string       $gateCode        Código del gate que falló
     * @param list<string> $missingFields   Campos requeridos faltantes ['field_name', ...]
     * @param list<string> $missingEvidence Evidencias requeridas faltantes ['evidence_type', ...]
     */
    public static function fail(
        string $message,
        string $gateCode,
        array $missingFields = [],
        array $missingEvidence = []
    ): self {
        return new self(
            passed: false,
            message: $message,
            gateCode: $gateCode,
            missingFields: $missingFields,
            missingEvidence: $missingEvidence
        );
    }

    /**
     * Crea un resultado de gate exceptuado (waived).
     * El gate fue saltado por una justificación válida.
     *
     * @param string $gateCode      Código del gate exceptuado
     * @param string $justification Razón de la excepción
     */
    public static function waived(string $gateCode, string $justification): self
    {
        return new self(
            passed: true,
            message: "Gate {$gateCode} waived: {$justification}",
            gateCode: $gateCode,
            missingFields: [],
            missingEvidence: []
        );
    }

    /**
     * Verifica si el gate fue superado (pasado o exceptuado).
     */
    public function isPassed(): bool
    {
        return $this->passed;
    }

    /**
     * Verifica si el resultado tiene campos faltantes.
     */
    public function hasMissingFields(): bool
    {
        return !empty($this->missingFields);
    }

    /**
     * Verifica si el resultado tiene evidencias faltantes.
     */
    public function hasMissingEvidence(): bool
    {
        return !empty($this->missingEvidence);
    }

    /**
     * Obtiene una representación en array para debugging o logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'message' => $this->message,
            'gateCode' => $this->gateCode,
            'missingFields' => $this->missingFields,
            'missingEvidence' => $this->missingEvidence,
        ];
    }

    /**
     * Projects this domain verdict onto the framework-agnostic core seam (D8).
     *
     * pass → PASSED, waived → WAIVED (gate code kept in metadata), fail → FAILED with the
     * fields and evidence flattened into the generic `missing` list. The field/evidence
     * split and the gate code survive in `metadata`, so {@see self::fromVerificationResult()}
     * can reconstruct this result without loss.
     *
     * A verifier that runs the gate machinery through the core {@see VerifierInterface} (D9,
     * e.g. {@see \Milpa\Workflow\Verification\StateMachineVerifier})
     * passes its own identity and the acting principal so the returned verdict is attributable.
     *
     * @param string|null $verifier  opaque id of the verifier producing this verdict (e.g. "workflow_engine")
     * @param string|null $principal opaque id of the acting principal (never resolved to an entity)
     */
    public function toVerificationResult(?string $verifier = null, ?string $principal = null): VerificationResult
    {
        $metadata = [];
        if ($this->gateCode !== null) {
            $metadata['gateCode'] = $this->gateCode;
        }
        if ($this->missingFields !== []) {
            $metadata['missingFields'] = $this->missingFields;
        }
        if ($this->missingEvidence !== []) {
            $metadata['missingEvidence'] = $this->missingEvidence;
        }

        if (!$this->passed) {
            return VerificationResult::fail(
                reason: $this->message ?? 'Gate failed',
                missing: array_merge($this->missingFields, $this->missingEvidence),
                verifier: $verifier,
                metadata: $metadata,
                principal: $principal,
            );
        }

        // A waived gate is the only passing verdict that carries a gate code + message;
        // a plain pass carries neither.
        if ($this->gateCode !== null) {
            return VerificationResult::waived(
                reason: $this->message ?? "Gate {$this->gateCode} waived",
                principal: $principal,
                verifier: $verifier,
                metadata: $metadata,
            );
        }

        return VerificationResult::pass(verifier: $verifier, principal: $principal);
    }

    /**
     * Rebuilds a domain verdict from a core {@see VerificationResult} — the inverse of
     * {@see self::toVerificationResult()}.
     *
     * A satisfied result (PASSED or WAIVED) maps to a passed gate; FAILED and the async
     * PENDING state map to a non-passing gate. The gate code and field/evidence split are
     * read back from metadata when present, and the reconstruction tolerates a bare core
     * result produced elsewhere (no metadata) without error.
     */
    public static function fromVerificationResult(VerificationResult $result): self
    {
        $metadata = $result->metadata;
        $gateCode = $metadata['gateCode'] ?? null;

        return new self(
            passed: $result->isSatisfied(),
            message: $result->reason,
            gateCode: is_string($gateCode) ? $gateCode : null,
            missingFields: self::stringList($metadata['missingFields'] ?? null),
            missingEvidence: self::stringList($metadata['missingEvidence'] ?? null),
        );
    }

    /**
     * Coerces an untyped metadata value into a clean list of strings.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($item): bool => is_string($item)));
    }
}
