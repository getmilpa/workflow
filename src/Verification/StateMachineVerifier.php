<?php

declare(strict_types=1);

namespace Milpa\Workflow\Verification;

use Milpa\Interfaces\Verification\VerifierInterface;
use Milpa\ValueObjects\Verification\VerificationContext;
use Milpa\ValueObjects\Verification\VerificationRequest;
use Milpa\ValueObjects\Verification\VerificationResult;
use Milpa\Workflow\Contracts\StateMachineInterface;
use Milpa\Workflow\StateMachine\GateResult;
use Milpa\Workflow\StateMachine\TransitionContext;

/**
 * Runs milpa/workflow's data-driven gate machinery as a first-class core
 * {@see VerifierInterface} (D8 seam / D9): a generic {@see VerificationRequest} + a
 * {@see VerificationContext} in, a {@see VerificationResult} out. This is the automated,
 * deterministic counterpart to the human-supplied verification tools (T089) — the same
 * seam, a different strategy.
 *
 * The engine evaluates a concrete transition, which the generic seam cannot express on its
 * own, so the transition and its evaluation inputs ride in `request->payload`:
 *
 *   - `domain`, `fromState`, `toState` (required) — the transition to check;
 *   - `actorId`, `actorRole`, `entityId` (optional) — acting actor and subject entity;
 *   - `gatePassages`, `evidenceIds` (optional int lists) — approved passages / attached evidence;
 *   - `fieldValues` (optional map) — required-field values.
 *
 * The acting principal comes from `context->principal`, and `context->metadata` passes through
 * verbatim (e.g. `waived_gate` / `justification`). The verdict is produced by bridging the
 * engine's {@see GateResult} through {@see GateResult::toVerificationResult()}, stamped with this
 * verifier's {@see self::NAME} and the principal.
 *
 * Framework-agnostic: depends only on the engine ({@see StateMachineInterface}) and the core
 * value objects — no Doctrine, no product-specific types (D9 / ADR-001).
 */
final class StateMachineVerifier implements VerifierInterface
{
    /** Opaque verifier identity stamped on every verdict this strategy produces. */
    public const NAME = 'workflow_engine';

    public function __construct(private readonly StateMachineInterface $stateMachine)
    {
    }

    /**
     * Evaluate the transition described by `request->payload` and return the bridged verdict.
     *
     * A payload missing `domain`, `fromState` or `toState` yields a FAILED result (the seam
     * never throws for malformed input — it reports it as an unsatisfied verification).
     */
    public function verify(VerificationRequest $request, VerificationContext $context): VerificationResult
    {
        $payload = $request->payload;
        $domain = $this->str($payload['domain'] ?? null);
        $fromState = $this->str($payload['fromState'] ?? null);
        $toState = $this->str($payload['toState'] ?? null);

        if ($domain === '' || $fromState === '' || $toState === '') {
            return VerificationResult::fail(
                'StateMachineVerifier requires payload.domain, payload.fromState and payload.toState.',
                verifier: self::NAME,
                principal: $context->principal,
            );
        }

        $transitionContext = new TransitionContext(
            actorId: $this->intOrNull($payload['actorId'] ?? null),
            actorRole: $this->strOrNull($payload['actorRole'] ?? null),
            entityId: $this->intOrNull($payload['entityId'] ?? null),
            domain: $domain,
            gatePassages: $this->intList($payload['gatePassages'] ?? null),
            evidenceIds: $this->intList($payload['evidenceIds'] ?? null),
            fieldValues: $this->stringKeyedArray($payload['fieldValues'] ?? null),
            metadata: $context->metadata,
        );

        $gateResult = $this->stateMachine->canTransition($domain, $fromState, $toState, $transitionContext);

        return $gateResult->toVerificationResult(self::NAME, $context->principal);
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function strOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_int($item)) {
                $out[] = $item;
            } elseif (is_numeric($item)) {
                $out[] = (int) $item;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
