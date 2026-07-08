<?php

declare(strict_types=1);

namespace Milpa\Workflow\Tests\Entities;

use PHPUnit\Framework\TestCase;
use Milpa\Workflow\Entities\Evidence;
use Milpa\Workflow\Entities\GatePassage;
use Milpa\Workflow\Enums\EvidenceType;

/**
 * Entity-level coverage of {@see Evidence} — no Doctrine, no EntityManager.
 */
final class EvidenceTest extends TestCase
{
    public function testUploadedByIsAnOpaqueString(): void
    {
        $evidence = (new Evidence())
            ->setType(EvidenceType::SOW_SIGNED)
            ->setTitle('Signed SOW')
            ->setUploadedBy('member:42')
            ->setGatePassage(new GatePassage());

        $this->assertSame('member:42', $evidence->getUploadedBy());
        $this->assertIsString($evidence->getUploadedBy());
    }

    public function testHasFileAndHasUrlReflectWhichIsSet(): void
    {
        $evidence = (new Evidence())->setFileUrl('https://example.com/sow.pdf');

        $this->assertFalse($evidence->hasFile());
        $this->assertTrue($evidence->hasUrl());
    }

    public function testFormattedFileSizeScalesUnits(): void
    {
        $bytes = (new Evidence())->setFileSize(512);
        $kb = (new Evidence())->setFileSize(2048);
        $mb = (new Evidence())->setFileSize(3 * 1048576);

        $this->assertSame('512 B', $bytes->getFormattedFileSize());
        $this->assertSame('2 KB', $kb->getFormattedFileSize());
        $this->assertSame('3 MB', $mb->getFormattedFileSize());
    }

    public function testIsImageChecksTheMimeTypePrefix(): void
    {
        $image = (new Evidence())->setMimeType('image/png');
        $pdf = (new Evidence())->setMimeType('application/pdf');

        $this->assertTrue($image->isImage());
        $this->assertFalse($pdf->isImage());
    }
}
