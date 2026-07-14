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
 * Thrown when {@see \Milpa\Workflow\Services\GatePassageService::waiveGate()} is called on a
 * {@see \Milpa\Workflow\Entities\GateDefinition} whose `isWaivable` flag is false.
 */
class NonWaivableGateException extends \RuntimeException
{
    private string $gateCode;

    public function __construct(string $gateCode)
    {
        $this->gateCode = $gateCode;

        $message = "Gate '{$gateCode}' no es dispensable (is_waivable=false)";
        parent::__construct($message);
    }

    public function getGateCode(): string
    {
        return $this->gateCode;
    }
}
