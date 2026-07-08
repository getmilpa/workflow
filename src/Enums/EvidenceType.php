<?php

declare(strict_types=1);

namespace Milpa\Workflow\Enums;

/**
 * The kind of evidence a {@see \Milpa\Workflow\Entities\Evidence} attachment carries
 * (e.g. a signed SOW, a QA report, a rollback plan).
 */
enum EvidenceType: string
{
    case BRIEF_DOC = 'brief_doc';
    case REQUIREMENTS_LIST = 'requirements_list';
    case FIT_SCORE_CHECKLIST = 'fit_score_checklist';
    case RISK_ASSESSMENT = 'risk_assessment';
    case SOW_SIGNED = 'sow_signed';
    case APPROVAL_NOTE = 'approval_note';
    case QA_REPORT = 'qa_report';
    case UAT_ACCEPTANCE = 'uat_acceptance';
    case RELEASE_CHECKLIST = 'release_checklist';
    case ROLLBACK_PLAN = 'rollback_plan';
    case CLIENT_BLOCKER_NOTE = 'client_blocker_note';
    case ADR_DOCUMENT = 'adr_document';
    case DEMO_RECORDING = 'demo_recording';
    case HANDOVER_DOCUMENT = 'handover_document';
    case MEETING_MINUTES = 'meeting_minutes';
    case SCOPE_DOCUMENT = 'scope_document';
    case ESTIMATION_SHEET = 'estimation_sheet';
    case PROPOSAL_DOCUMENT = 'proposal_document';
    case SIGNED_PROPOSAL = 'signed_proposal';
    case SIGNED_SOW = 'signed_sow';
    case SOLUTION_DESIGN_DOCUMENT = 'solution_design_document';

    /**
     * Human-readable label for this evidence type.
     */
    public function label(): string
    {
        return match($this) {
            self::BRIEF_DOC => 'Brief del Proyecto',
            self::REQUIREMENTS_LIST => 'Lista de Requerimientos',
            self::FIT_SCORE_CHECKLIST => 'Checklist de Calificación',
            self::RISK_ASSESSMENT => 'Evaluación de Riesgos',
            self::SOW_SIGNED => 'SOW Firmado',
            self::APPROVAL_NOTE => 'Nota de Aprobación',
            self::QA_REPORT => 'Reporte de QA',
            self::UAT_ACCEPTANCE => 'Aceptación UAT',
            self::RELEASE_CHECKLIST => 'Checklist de Release',
            self::ROLLBACK_PLAN => 'Plan de Rollback',
            self::CLIENT_BLOCKER_NOTE => 'Nota de Bloqueo por Cliente',
            self::ADR_DOCUMENT => 'Architecture Decision Record',
            self::DEMO_RECORDING => 'Grabación de Demo',
            self::HANDOVER_DOCUMENT => 'Documento de Handover',
            self::MEETING_MINUTES => 'Minutas de Reunión',
            self::SCOPE_DOCUMENT => 'Documento de Alcance',
            self::ESTIMATION_SHEET => 'Hoja de Estimación',
            self::PROPOSAL_DOCUMENT => 'Documento de Propuesta',
            self::SIGNED_PROPOSAL => 'Propuesta Firmada',
            self::SIGNED_SOW => 'SOW Firmado (Cliente)',
            self::SOLUTION_DESIGN_DOCUMENT => 'Documento de Diseno de Solucion',
        };
    }
}
