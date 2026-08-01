<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\Entities;

use PHPUnit\Framework\TestCase;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\Evidence;
use Milpa\Workflow\Entities\GatePassage;
use Milpa\Workflow\Enums\GatePassageStatus;
use Milpa\Workflow\Tests\Support\EntityIdSetter;

/**
 * Entity-level coverage of {@see GatePassage} — no Doctrine, no EntityManager, just `new` +
 * setters (Doctrine's `#[ORM\...]` attributes are metadata only).
 *
 * `requestedBy`/`approvedBy` are opaque principal strings (e.g. "member:42"), finishing the
 * opacity that {@see Evidence::getUploadedBy()} already had (the map's residue #2: before this
 * change these two fields were still raw `int` while `Evidence.uploadedBy` was already a
 * string, an inconsistent public API). This test pins that they round-trip as strings and
 * that `toArray()` exposes them under `requested_by`/`approved_by` (no `_id` suffix, matching
 * `Evidence`'s `uploaded_by` key).
 */
final class GatePassageTest extends TestCase
{
    private function passage(): GatePassage
    {
        $gate = (new GateDefinition())->setCode('qualification_gate')->setName('Qualification Gate');
        EntityIdSetter::set($gate, 5);

        $passage = new GatePassage();
        $passage->setGateDefinition($gate);
        $passage->setEntityType('opportunity');
        $passage->setEntityId(99);
        $passage->setRequestedBy('member:7');
        $passage->setStatus(GatePassageStatus::REQUESTED);
        EntityIdSetter::set($passage, 1);

        return $passage;
    }

    public function testRequestedByIsAnOpaqueStringNeverAnInt(): void
    {
        $passage = $this->passage();

        $this->assertSame('member:7', $passage->getRequestedBy());
        $this->assertIsString($passage->getRequestedBy());
        $this->assertNull($passage->getApprovedBy());
    }

    public function testApproveStoresTheApproverAsAnOpaqueString(): void
    {
        $passage = $this->passage();

        $passage->approve('member:12', 'fit score confirmed');

        $this->assertTrue($passage->isApproved());
        $this->assertSame('member:12', $passage->getApprovedBy());
        $this->assertIsString($passage->getApprovedBy());
        $this->assertSame('fit score confirmed', $passage->getApprovedNotes());
    }

    public function testRejectStoresTheRejectorInTheSameApprovedByField(): void
    {
        $passage = $this->passage();

        $passage->reject('member:12', 'insufficient evidence');

        $this->assertTrue($passage->isRejected());
        $this->assertSame('member:12', $passage->getApprovedBy());
        $this->assertSame('insufficient evidence', $passage->getRejectedReason());
    }

    public function testToArrayExposesOpaqueKeysWithoutTheStaleIdSuffix(): void
    {
        $passage = $this->passage();
        $passage->approve('member:12');

        $array = $passage->toArray();

        $this->assertSame('member:7', $array['requested_by']);
        $this->assertSame('member:12', $array['approved_by']);
        $this->assertArrayNotHasKey('requested_by_id', $array);
        $this->assertArrayNotHasKey('approved_by_id', $array);
    }

    public function testIsPendingBeforeResolutionAndFinalAfter(): void
    {
        $passage = $this->passage();

        $this->assertTrue($passage->isPending());
        $this->assertFalse($passage->isApproved());
        $this->assertFalse($passage->isRejected());

        $passage->approve('member:12');

        $this->assertFalse($passage->isPending());
        $this->assertTrue($passage->isApproved());
    }

    /**
     * Los accesores y las notas: aburridos, y sin ellos el pase no se puede auditar.
     *
     * Se prueban juntos a proposito. Cada uno por separado seria una prueba que repite el nombre del
     * metodo; juntos verifican lo unico que importa de este objeto — que lo que le escribiste es lo
     * que devuelve, porque de ahi sale el registro que alguien va a leer manana.
     */
    public function testTheAccessorsReturnWhatWasWritten(): void
    {
        $passage = new GatePassage();
        $passage->setGateDefinition(new GateDefinition());
        $passage->setEntityType('opportunity')->setEntityId(7)->setRequestedBy('member:1');
        $passage->setRequestedNotes('viene del formulario')
            ->setApprovedNotes('revisado')
            ->setRejectedReason('sin presupuesto')
            ->setMetadata(['campo' => 'valor']);

        self::assertNotSame('', $passage->getUuid(), 'el uuid se acuña al construir');
        self::assertSame(GatePassageStatus::REQUESTED, $passage->getStatus());
        self::assertSame('viene del formulario', $passage->getRequestedNotes());
        self::assertSame('revisado', $passage->getApprovedNotes());
        self::assertSame('sin presupuesto', $passage->getRejectedReason());
        self::assertSame(['campo' => 'valor'], $passage->getMetadata());
        self::assertInstanceOf(\DateTime::class, $passage->getCreatedAt());
        self::assertCount(0, $passage->getEvidences());
    }

    /**
     * La evidencia se agrega una sola vez y se puede quitar.
     *
     * El `contains` no es paranoia: una coleccion Doctrine acepta el mismo objeto dos veces, y un
     * pase con la misma evidencia duplicada hace que quien la cuente para decidir si el gate esta
     * completo cuente de mas.
     */
    public function testEvidenceIsAddedOnceAndCanBeRemoved(): void
    {
        $passage = new GatePassage();
        $evidencia = new Evidence();

        $passage->addEvidence($evidencia);
        $passage->addEvidence($evidencia);
        self::assertCount(1, $passage->getEvidences(), 'la misma dos veces sigue siendo una');

        $passage->removeEvidence($evidencia);
        self::assertCount(0, $passage->getEvidences());
    }
}
