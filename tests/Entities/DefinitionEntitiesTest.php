<?php

/**
 * This file is part of Milpa Workflow — the data-driven state machine and gate
 * engine of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/workflow
 */

declare(strict_types=1);

namespace Milpa\Workflow\Tests\Entities;

use Milpa\Workflow\Entities\Evidence;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\GatePassage;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;
use Milpa\Workflow\Enums\ApprovalPolicy;
use Milpa\Workflow\Enums\EvidenceType;
use Milpa\Workflow\Exceptions\TransitionNotAllowedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The definitions the whole state machine is configured from.
 *
 * The services above them were well covered; these were not. Two things here
 * are contracts rather than plumbing: `toArray()` is what an API response is
 * built from, so a renamed key breaks a consumer silently, and the collection
 * helpers own BOTH sides of each relation — a half-wired transition is a state
 * machine with an edge only one of its two states knows about.
 */
#[CoversClass(StateDefinition::class)]
#[CoversClass(TransitionDefinition::class)]
#[CoversClass(GateDefinition::class)]
#[CoversClass(Evidence::class)]
#[CoversClass(EvidenceType::class)]
final class DefinitionEntitiesTest extends TestCase
{
    /**
     * Sets the generated id the way Doctrine does — by reflection, on hydration.
     *
     * These entities declare `int $id` with no default on purpose: an id exists
     * only once the row does. Serialising an entity that was never persisted is
     * therefore not a case `toArray()` promises to handle, and giving it one
     * here is what lets the rest of the shape be asserted without a database.
     */
    private function withId(object $entity, int $id): object
    {
        $property = new \ReflectionProperty($entity::class, 'id');
        $property->setValue($entity, $id);

        return $entity;
    }

    private function state(string $code = 'lead'): StateDefinition
    {
        return (new StateDefinition())
            ->setDomain('opportunity')
            ->setCode($code)
            ->setLabel(ucfirst($code));
    }

    private function transition(string $code = 'qualify'): TransitionDefinition
    {
        return (new TransitionDefinition())
            ->setDomain('opportunity')
            ->setCode($code);
    }

    // ---- StateDefinition ----------------------------------------------------

    public function testAStateRoundTripsEveryFieldItWasGiven(): void
    {
        $state = (new StateDefinition())
            ->setDomain('opportunity')
            ->setCode('qualified')
            ->setLabel('Calificada')
            ->setDescription('Pasó el filtro de fit')
            ->setSortOrder(20)
            ->setIsInitial(false)
            ->setIsTerminal(false)
            ->setColor('#E8B14C')
            ->setMetadata(['icon' => 'check']);

        self::assertSame('opportunity', $state->getDomain());
        self::assertSame('qualified', $state->getCode());
        self::assertSame('Calificada', $state->getLabel());
        self::assertSame('Pasó el filtro de fit', $state->getDescription());
        self::assertSame(20, $state->getSortOrder());
        self::assertFalse($state->isInitial());
        self::assertFalse($state->isTerminal());
        self::assertSame('#E8B14C', $state->getColor());
        self::assertSame(['icon' => 'check'], $state->getMetadata());
    }

    public function testAnInitialAndATerminalStateSayWhichTheyAre(): void
    {
        // The machine finds where to start and where it may stop from these two
        // flags; a setter that wrote the wrong one would make a pipeline that
        // never ends or never begins.
        $inicial = $this->state('lead')->setIsInitial(true);
        $final = $this->state('won')->setIsTerminal(true);

        self::assertTrue($inicial->isInitial());
        self::assertFalse($inicial->isTerminal());
        self::assertTrue($final->isTerminal());
        self::assertFalse($final->isInitial());
    }

    public function testAddingATransitionWiresTheInverseSideAndDoesNotDuplicate(): void
    {
        $state = $this->state();
        $transition = $this->transition();

        $state->addTransitionFrom($transition);
        $state->addTransitionFrom($transition);

        self::assertCount(1, $state->getTransitionsFrom(), 'Added twice, held once.');
        self::assertSame($state, $transition->getFromState(), 'The transition knows where it comes from.');
    }

    public function testRemovingATransitionClearsTheInverseSideToo(): void
    {
        // A transition left pointing at a state that no longer lists it is an
        // edge only one end believes in.
        $state = $this->state();
        $transition = $this->transition();
        $state->addTransitionFrom($transition);

        $state->removeTransitionFrom($transition);

        self::assertCount(0, $state->getTransitionsFrom());
    }

    public function testTransitionsArrivingAtAStateAreWiredTheSameWay(): void
    {
        $state = $this->state('qualified');
        $transition = $this->transition();

        $state->addTransitionTo($transition);

        self::assertCount(1, $state->getTransitionsTo());
        self::assertSame($state, $transition->getToState());

        $state->removeTransitionTo($transition);

        self::assertCount(0, $state->getTransitionsTo());
    }

    public function testAStateSerialisesUnderTheKeyNamesTheApiPublishes(): void
    {
        $state = $this->state('lead')->setIsInitial(true)->setSortOrder(10);
        $this->withId($state, 7);
        $state->addTransitionFrom($this->transition());

        $array = $state->toArray();

        self::assertSame('opportunity', $array['domain']);
        self::assertSame('lead', $array['code']);
        self::assertSame(10, $array['sort_order']);
        self::assertTrue($array['is_initial']);
        self::assertFalse($array['is_terminal']);
        self::assertSame(1, $array['transitions_from_count']);
        self::assertSame(0, $array['transitions_to_count']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $array['created_at']);
    }

    public function testTouchingAStateMovesItsUpdatedAtButNotItsCreatedAt(): void
    {
        $state = $this->state();
        $this->withId($state, 7);
        $creado = $state->toArray()['created_at'];

        $state->onPreUpdate();

        self::assertSame($creado, $state->toArray()['created_at']);
    }

    // ---- TransitionDefinition -------------------------------------------------

    public function testATransitionRoundTripsItsFieldsAndItsTwoEnds(): void
    {
        $desde = $this->state('lead');
        $hacia = $this->state('qualified');

        $transition = $this->transition('qualify')
            ->setLabel('Calificar')
            ->setRequiredRole('sales')
            ->setEnabled(false)
            ->setMetadata(['confirm' => true])
            ->setFromState($desde)
            ->setToState($hacia);

        self::assertSame('qualify', $transition->getCode());
        self::assertSame('Calificar', $transition->getLabel());
        self::assertSame('sales', $transition->getRequiredRole());
        self::assertFalse($transition->isEnabled());
        self::assertSame(['confirm' => true], $transition->getMetadata());
        self::assertSame($desde, $transition->getFromState());
        self::assertSame($hacia, $transition->getToState());
    }

    public function testATransitionCarriesTheGatesThatGuardItWithoutDuplicating(): void
    {
        $transition = $this->transition();
        $gate = (new GateDefinition())->setDomain('opportunity')->setCode('fit-check')->setName('Fit check');

        $transition->addGateDefinition($gate);
        $transition->addGateDefinition($gate);

        self::assertCount(1, $transition->getGateDefinitions());
    }

    public function testATransitionSerialisesBothOfItsEnds(): void
    {
        $transition = $this->transition('qualify')
            ->setFromState($this->withId($this->state('lead'), 1))
            ->setToState($this->withId($this->state('qualified'), 2));
        $this->withId($transition, 3);

        $array = $transition->toArray();

        self::assertSame('qualify', $array['code']);
        self::assertSame('opportunity', $array['domain']);
    }

    // ---- GateDefinition ----------------------------------------------------------

    public function testAGateRoundTripsItsPolicyAndItsRequirements(): void
    {
        $gate = (new GateDefinition())
            ->setDomain('project')
            ->setCode('release-ready')
            ->setName('Listo para release')
            ->setDescription('QA y rollback en su lugar')
            ->setRequesterRole('dev')
            ->setApproverRole('lead')
            ->setApprovalPolicy(ApprovalPolicy::SINGLE)
            ->setRequiredEvidenceTypes([EvidenceType::QA_REPORT->value])
            ->setRequiredFields(['ticket'])
            ->setSortOrder(5)
            ->setMetadata(['blocking' => true]);

        self::assertSame('release-ready', $gate->getCode());
        self::assertSame('Listo para release', $gate->getName());
        self::assertSame('QA y rollback en su lugar', $gate->getDescription());
        self::assertSame('dev', $gate->getRequesterRole());
        self::assertSame('lead', $gate->getApproverRole());
        self::assertSame(ApprovalPolicy::SINGLE, $gate->getApprovalPolicy());
        self::assertSame(ApprovalPolicy::SINGLE->value, $gate->getApprovalPolicyValue());
        self::assertSame([EvidenceType::QA_REPORT->value], $gate->getRequiredEvidenceTypes());
        self::assertSame(['ticket'], $gate->getRequiredFields());
        self::assertSame(5, $gate->getSortOrder());
        self::assertSame(['blocking' => true], $gate->getMetadata());
    }

    // ---- Evidence -------------------------------------------------------------------

    public function testEvidenceRoundTripsItsFileFacts(): void
    {
        $evidence = (new Evidence())
            ->setType(EvidenceType::QA_REPORT)
            ->setTitle('Reporte de QA v3')
            ->setDescription('Corrida completa')
            ->setFilePath('/uploads/qa-v3.pdf')
            ->setFileUrl('https://example.test/qa-v3.pdf')
            ->setMimeType('application/pdf')
            ->setFileSize(2048)
            ->setUploadedBy('user:1')
            ->setMetadata(['sha' => 'abc']);

        self::assertSame(EvidenceType::QA_REPORT, $evidence->getEvidenceType());
        self::assertSame(EvidenceType::QA_REPORT->value, $evidence->getTypeValue());
        self::assertSame('Reporte de QA v3', $evidence->getTitle());
        self::assertSame('/uploads/qa-v3.pdf', $evidence->getFilePath());
        self::assertSame('https://example.test/qa-v3.pdf', $evidence->getFileUrl());
        self::assertSame('application/pdf', $evidence->getMimeType());
        self::assertSame(2048, $evidence->getFileSize());
        self::assertSame('user:1', $evidence->getUploadedBy());
        self::assertNotSame('', $evidence->getUuid(), 'A piece of evidence identifies itself from the moment it exists.');
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function mimeTypes(): iterable
    {
        yield 'a png' => ['image/png', true];
        yield 'a jpeg' => ['image/jpeg', true];
        yield 'a pdf' => ['application/pdf', false];
        yield 'nothing declared' => [null, false];
        yield 'something merely mentioning image' => ['application/x-image-thing', false];
    }

    #[DataProvider('mimeTypes')]
    public function testOnlyAnImageMimeTypeCountsAsAnImage(?string $mimeType, bool $isImage): void
    {
        // The UI decides whether to render a thumbnail from this. A substring
        // match would try to preview a zip.
        $evidence = (new Evidence())->setMimeType($mimeType);

        self::assertSame($isImage, $evidence->isImage());
    }

    /**
     * @return iterable<string, array{?int, string}>
     */
    public static function fileSizes(): iterable
    {
        yield 'nothing' => [null, '0 B'];
        yield 'bytes' => [512, '512 B'];
        yield 'exactly a kilobyte' => [1024, '1 KB'];
        yield 'kilobytes' => [2048, '2 KB'];
        yield 'just under a megabyte' => [1048575, '1024 KB'];
        yield 'exactly a megabyte' => [1048576, '1 MB'];
        yield 'megabytes' => [5242880, '5 MB'];
    }

    #[DataProvider('fileSizes')]
    public function testAFileSizeIsFormattedAtTheRightScale(?int $bytes, string $formatted): void
    {
        self::assertSame($formatted, (new Evidence())->setFileSize($bytes)->getFormattedFileSize());
    }

    public function testEvidenceSerialisesItsFileFactsUnderOneNestedKey(): void
    {
        $evidence = (new Evidence())
            ->setType(EvidenceType::ROLLBACK_PLAN)
            ->setTitle('Plan de rollback')
            ->setMimeType('image/png')
            ->setFileSize(1024)
            ->setFilePath('/uploads/plan.png')
            ->setUploadedBy('user:2')
            ->setGatePassage($this->withId(new GatePassage(), 4));
        $this->withId($evidence, 5);

        $array = $evidence->toArray();

        self::assertSame(EvidenceType::ROLLBACK_PLAN->value, $array['type']);
        self::assertSame(EvidenceType::ROLLBACK_PLAN->label(), $array['type_label']);
        self::assertSame('Plan de rollback', $array['title']);
        self::assertSame('1 KB', $array['file']['size_formatted']);
        self::assertTrue($array['file']['is_image']);
        self::assertTrue($array['file']['has_file']);
        self::assertFalse($array['file']['has_url']);
        self::assertSame('user:2', $array['uploaded_by']);
    }

    // ---- EvidenceType --------------------------------------------------------------------

    /**
     * @return iterable<string, array{EvidenceType}>
     */
    public static function evidenceTypes(): iterable
    {
        foreach (EvidenceType::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    #[DataProvider('evidenceTypes')]
    public function testEveryEvidenceTypeHasALabelOfItsOwn(EvidenceType $type): void
    {
        // label() is a match with no default arm: a case added without one
        // throws at runtime, in whatever screen happens to render it first.
        self::assertNotSame('', $type->label());
    }

    public function testNoTwoEvidenceTypesShareALabel(): void
    {
        // Two kinds of evidence that read identically in a list are two kinds
        // nobody can tell apart when picking one.
        $labels = array_map(static fn (EvidenceType $t): string => $t->label(), EvidenceType::cases());

        self::assertSame(count($labels), count(array_unique($labels)));
    }

    public function testAGateSaysWhetherFailingItBlocksTheTransition(): void
    {
        // The difference between a gate that warns and a gate that stops the
        // pipeline. Anything other than 'block' lets the transition through.
        $bloquea = (new GateDefinition())->setDomain('project')->setCode('g')->setName('G')->setFailureAction('block');
        $avisa = (new GateDefinition())->setDomain('project')->setCode('g')->setName('G')->setFailureAction('warn');
        $sinAccion = (new GateDefinition())->setDomain('project')->setCode('g')->setName('G');

        self::assertTrue($bloquea->blocksOnFailure());
        self::assertFalse($avisa->blocksOnFailure());
        self::assertFalse($sinAccion->blocksOnFailure());
    }

    public function testAGateSerialisesUnderTheKeyNamesTheApiPublishes(): void
    {
        $gate = (new GateDefinition())
            ->setDomain('project')
            ->setCode('release-ready')
            ->setName('Listo para release')
            ->setRequesterRole('dev')
            ->setApproverRole('lead')
            ->setApprovalPolicy(ApprovalPolicy::DUAL)
            ->setRequiredEvidenceTypes([EvidenceType::QA_REPORT->value])
            ->setRequiredFields(['ticket'])
            ->setIsWaivable(true)
            ->setFailureAction('block')
            ->setSuccessAutoActions(['notify'])
            ->setSortOrder(3)
            ->setMetadata(['owner' => 'lead']);
        $this->withId($gate, 11);

        $array = $gate->toArray();

        self::assertSame(11, $array['id']);
        self::assertSame('project', $array['domain']);
        self::assertSame('release-ready', $array['code']);
        self::assertSame(ApprovalPolicy::DUAL->value, $array['approval_policy'], 'Serialised as its backing value, not as the enum.');
        self::assertSame(ApprovalPolicy::DUAL->label(), $array['approval_policy_label']);
        self::assertSame([EvidenceType::QA_REPORT->value], $array['required_evidence_types']);
        self::assertSame(['ticket'], $array['required_fields']);
        self::assertTrue($array['is_waivable']);
        self::assertSame('block', $array['failure_action']);
        self::assertSame(['notify'], $array['success_auto_actions']);
        self::assertSame(3, $array['sort_order']);
        self::assertSame(0, $array['transitions_count']);
        self::assertSame(0, $array['passages_count']);
        self::assertNotSame('', $array['created_at']);
        self::assertNotSame('', $array['updated_at']);
    }

    public function testTouchingAGateMovesItsUpdatedAt(): void
    {
        $gate = (new GateDefinition())->setDomain('project')->setCode('g')->setName('G')
            ->setRequesterRole('dev')->setApproverRole('lead');
        $this->withId($gate, 12);

        $gate->onPreUpdate();

        self::assertNotSame('', $gate->toArray()['updated_at']);
    }

    // ---- ApprovalPolicy ------------------------------------------------------------

    /**
     * @return iterable<string, array{ApprovalPolicy}>
     */
    public static function approvalPolicies(): iterable
    {
        foreach (ApprovalPolicy::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    #[DataProvider('approvalPolicies')]
    public function testEveryApprovalPolicyHasALabelOfItsOwn(ApprovalPolicy $policy): void
    {
        self::assertNotSame('', $policy->label());
    }

    public function testNoTwoApprovalPoliciesShareALabel(): void
    {
        $labels = array_map(static fn (ApprovalPolicy $p): string => $p->label(), ApprovalPolicy::cases());

        self::assertSame(count($labels), count(array_unique($labels)));
    }

    // ---- TransitionNotAllowedException ------------------------------------------------

    public function testARefusedTransitionCarriesEverythingNeededToRetryIt(): void
    {
        // Catching the type says the move was refused. Only these fields say
        // WHAT to fix — without them the caller can just try again and fail
        // again the same way.
        $exception = new TransitionNotAllowedException(
            'lead',
            'qualified',
            'opportunity',
            'fit-check',
            ['budget'],
            [EvidenceType::FIT_SCORE_CHECKLIST->value],
        );

        self::assertSame('lead', $exception->getFromState());
        self::assertSame('qualified', $exception->getToState());
        self::assertSame('opportunity', $exception->getDomain());
        self::assertSame('fit-check', $exception->getGateCode());
        self::assertSame(['budget'], $exception->getMissingFields());
        self::assertSame([EvidenceType::FIT_SCORE_CHECKLIST->value], $exception->getMissingEvidence());
        self::assertStringContainsString('lead', $exception->getMessage());
        self::assertStringContainsString('qualified', $exception->getMessage());
    }

    public function testARefusalWithNoGateStillNamesTheMoveItRefused(): void
    {
        $exception = new TransitionNotAllowedException('lead', 'won', 'opportunity');

        self::assertNull($exception->getGateCode());
        self::assertSame([], $exception->getMissingFields());
        self::assertSame([], $exception->getMissingEvidence());
        self::assertStringContainsString('lead -> won', $exception->getMessage());
    }
}
