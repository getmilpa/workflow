<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\StateMachine;

use PHPUnit\Framework\TestCase;
use Milpa\Enums\VerificationStatus;
use Milpa\ValueObjects\Verification\VerificationResult;
use Milpa\Workflow\StateMachine\GateResult;

/**
 * Ported from the monorepo's `tests/Unit/Verification/GateResultBridgeTest.php` (T090):
 * the bridge that ports the engine's domain `GateResult` onto the framework-agnostic core
 * `VerificationResult` (and back). Pure value-object logic — no Doctrine.
 *
 * The domain type keeps its public API (zero blast radius on its live consumers); this test
 * pins the mapping and the lossless round-trip the state machine leans on when speaking
 * `VerificationResult` at its boundary.
 */
final class GateResultTest extends TestCase
{
    // --- forward projection: GateResult -> VerificationResult -----------------

    public function testPassProjectsToPassed(): void
    {
        $vr = GateResult::pass()->toVerificationResult();

        $this->assertSame(VerificationStatus::PASSED, $vr->status);
        $this->assertTrue($vr->isSatisfied());
        $this->assertFalse($vr->hasMissing());
        $this->assertArrayNotHasKey('gateCode', $vr->metadata);
    }

    public function testFailProjectsToFailedWithFlattenedMissingAndMetadata(): void
    {
        $vr = GateResult::fail(
            'Gate qualification_gate requires fit_score',
            'qualification_gate',
            ['fit_score', 'budget'],
            ['signed_document'],
        )->toVerificationResult();

        $this->assertSame(VerificationStatus::FAILED, $vr->status);
        $this->assertFalse($vr->isSatisfied());
        $this->assertSame('Gate qualification_gate requires fit_score', $vr->reason);
        // fields + evidence flatten into the generic `missing` list...
        $this->assertSame(['fit_score', 'budget', 'signed_document'], $vr->missing);
        // ...while the domain split + gate code survive in metadata for round-tripping.
        $this->assertSame('qualification_gate', $vr->metadata['gateCode']);
        $this->assertSame(['fit_score', 'budget'], $vr->metadata['missingFields']);
        $this->assertSame(['signed_document'], $vr->metadata['missingEvidence']);
    }

    public function testWaivedProjectsToWaivedCarryingGateCode(): void
    {
        $vr = GateResult::waived('budget_approval_gate', 'CEO override')->toVerificationResult();

        $this->assertSame(VerificationStatus::WAIVED, $vr->status);
        $this->assertTrue($vr->isSatisfied());
        $this->assertStringContainsString('waived', $vr->reason ?? '');
        $this->assertSame('budget_approval_gate', $vr->metadata['gateCode']);
    }

    // --- round trip: GateResult -> VerificationResult -> GateResult -----------

    public function testPassRoundTripsLosslessly(): void
    {
        $back = GateResult::fromVerificationResult(GateResult::pass()->toVerificationResult());

        $this->assertTrue($back->isPassed());
        $this->assertNull($back->message);
        $this->assertNull($back->gateCode);
        $this->assertSame([], $back->missingFields);
        $this->assertSame([], $back->missingEvidence);
    }

    public function testFailRoundTripsLosslessly(): void
    {
        $original = GateResult::fail(
            'Gate sow_signed_gate requires campos faltantes: [timeline]',
            'sow_signed_gate',
            ['timeline'],
            ['contract_pdf'],
        );

        $back = GateResult::fromVerificationResult($original->toVerificationResult());

        $this->assertFalse($back->isPassed());
        $this->assertSame($original->message, $back->message);
        $this->assertSame('sow_signed_gate', $back->gateCode);
        $this->assertSame(['timeline'], $back->missingFields);
        $this->assertSame(['contract_pdf'], $back->missingEvidence);
    }

    public function testWaivedRoundTripsLosslessly(): void
    {
        $original = GateResult::waived('budget_approval_gate', 'Emergency, CEO override');

        $back = GateResult::fromVerificationResult($original->toVerificationResult());

        $this->assertTrue($back->isPassed());
        $this->assertSame('budget_approval_gate', $back->gateCode);
        $this->assertSame($original->message, $back->message);
        $this->assertSame([], $back->missingFields);
        $this->assertSame([], $back->missingEvidence);
    }

    // --- reverse robustness ---------------------------------------------------

    public function testPendingCoreResultMapsToNonPassingGate(): void
    {
        $back = GateResult::fromVerificationResult(VerificationResult::pending(verifier: 'human_verify'));

        $this->assertFalse($back->isPassed(), 'an unresolved async verify is not a passed gate');
    }

    public function testBareCoreFailWithoutMetadataReconstructsSafely(): void
    {
        $back = GateResult::fromVerificationResult(VerificationResult::fail('nope'));

        $this->assertFalse($back->isPassed());
        $this->assertSame('nope', $back->message);
        $this->assertNull($back->gateCode);
        $this->assertSame([], $back->missingFields);
        $this->assertSame([], $back->missingEvidence);
    }

    // --- verifier/principal stamping (T094: WF as a first-class VerifierInterface) ---

    public function testToVerificationResultStampsVerifierAndPrincipalAcrossVerdicts(): void
    {
        $fail = GateResult::fail('nope', 'g', ['f'], [])->toVerificationResult('workflow_engine', 'user:42');
        $this->assertSame('workflow_engine', $fail->verifier);
        $this->assertSame('user:42', $fail->principal);

        $pass = GateResult::pass()->toVerificationResult('workflow_engine', 'user:7');
        $this->assertSame('workflow_engine', $pass->verifier);
        $this->assertSame('user:7', $pass->principal);

        $waived = GateResult::waived('g', 'exec override')->toVerificationResult('workflow_engine', 'user:9');
        $this->assertSame('workflow_engine', $waived->verifier);
        $this->assertSame('user:9', $waived->principal);
    }
}
