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
 * Thrown when a {@see \Milpa\Workflow\Entities\GatePassage}'s requester and approver would be
 * the same opaque principal (e.g. "member:42") — the anti-self-approval constraint (D9).
 */
class SelfApprovalException extends \RuntimeException
{
    private string $principal;
    private string $gateCode;

    public function __construct(string $principal, string $gateCode)
    {
        $this->principal = $principal;
        $this->gateCode = $gateCode;

        $message = "Auto-aprobación prohibida: '{$principal}' no puede aprobar su propio gate '{$gateCode}'";
        parent::__construct($message);
    }

    public function getPrincipal(): string
    {
        return $this->principal;
    }

    public function getGateCode(): string
    {
        return $this->gateCode;
    }
}
