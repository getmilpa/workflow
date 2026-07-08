<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Workflow

> The **ORM-backed, data-driven state machine** of the Milpa PHP framework: states,
> transitions and approval **gates** — with evidence attachments — configured in the
> database instead of hardcoded, plus `StateMachineVerifier`, a bridge onto
> `milpa/core`'s `VerifierInterface`.

[![CI](https://github.com/getmilpa/workflow/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/workflow/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/workflow.svg)](https://packagist.org/packages/milpa/workflow)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/workflow/)

`milpa/workflow` is the family's first **tier-2** package: Doctrine ORM is an honest
runtime dependency (used only through `findBy`/`findOneBy`/`QueryBuilder` on the
package's own entities), not a zero-dep primitive like `milpa/core`. Everything else
about the family still holds — no product coupling, no concrete container, opaque
identity.

## Install

```bash
composer require milpa/workflow
```

## Quick example

```php
use Milpa\Workflow\StateMachine\DataDrivenStateMachine;
use Milpa\Workflow\StateMachine\TransitionContext;
use Milpa\Workflow\Gates\DefaultGateEvaluator;
use Milpa\Workflow\Services\GatePassageService;

// $em is a Doctrine\ORM\EntityManagerInterface wired to a schema that has run
// doctrine/orm's schema tool over Milpa\Workflow\Entities\*; $gate is a
// GateDefinition your domain configured (e.g. persisted via a migration/seeder).
$stateMachine = new DataDrivenStateMachine($em, new DefaultGateEvaluator());
$gateService = new GatePassageService($em);

// Ask whether 'lead' -> 'qualified' is currently possible for domain 'opportunity'.
$context = new TransitionContext(actorId: 7, actorRole: 'sales', entityId: 99, domain: 'opportunity');
$result = $stateMachine->canTransition('opportunity', 'lead', 'qualified', $context);

if (!$result->isPassed()) {
    // $result->missingFields / $result->missingEvidence explain exactly what's missing.
}

// Request (and later approve) a gate passage against a GateDefinition your domain
// configured (e.g. `$em->getRepository(GateDefinition::class)->findOneBy([...])`).
// Principals are opaque strings the engine never resolves — the consuming product
// owns identity.
$passage = $gateService->requestPassage($gate, 'opportunity', 99, requesterId: 'member:7');
$gateService->approvePassage($passage, approverId: 'member:12', notes: 'fit score confirmed');
```

## What it does — and doesn't

- **`Contracts\StateMachineInterface`** (implemented by `StateMachine\DataDrivenStateMachine`)
  — reads `Entities\StateDefinition`/`TransitionDefinition`/`GateDefinition` from the
  database to answer `canTransition()`, `getAvailableTransitions()`, `transition()`,
  and lookups by domain/code. No transition graph is hardcoded in PHP.
- **`Contracts\GateServiceInterface`** (implemented by `Services\GatePassageService`)
  — request/approve/reject/waive an append-only `Entities\GatePassage`, with the
  anti-self-approval constraint enforced at the service layer
  (`Exceptions\SelfApprovalException`).
- **`Verification\StateMachineVerifier`** — runs the gate machinery through
  `milpa/core`'s `VerifierInterface`: a generic `VerificationRequest`/`VerificationContext`
  in, a `VerificationResult` out, bridged losslessly through `StateMachine\GateResult`.
  This is the monorepo's only automated `VerifierInterface` implementation — the
  deterministic counterpart to a human/agent-supplied verification.
- **Opaque identity everywhere a principal is stored**: `Entities\Evidence::$uploadedBy`
  and `Entities\GatePassage::$requestedBy`/`$approvedBy` are plain strings such as
  `"member:42"`. The engine never resolves them to an entity — the consuming product
  owns identity resolution (see [`ADR-001`](https://github.com/getmilpa/core) /
  design note D9).
- **No product coupling.** The polymorphic `entity_type`/`entity_id` pair on
  `GatePassage` lets any domain (opportunity, project, invoice, ...) register its own
  states/transitions/gates without this package knowing about it.

## Namespace layout

Public contracts live under `Contracts/` (not `Interfaces/`, which is `milpa/core`'s
established, already-published convention) — this package follows the family
convention adopted from ola 2 onward: new packages use `Contracts/` for
interfaces/value-objects that form the package's public seam, while
concern-scoped strategy interfaces stay colocated with their concern
(`StateMachine\GateEvaluatorInterface` lives in `StateMachine/`, next to the
`GateResult`/`TransitionContext` types it operates on).

## Testing

The test suite is standalone: it exercises the state machine's transition/gate
logic and the `StateMachineVerifier` bridge against **stub implementations** of
`Contracts\StateMachineInterface`/`StateMachine\GateEvaluatorInterface` and
hand-built entity graphs — no live database, no Doctrine bootstrap. The two
Doctrine-backed services (`StateMachine\DataDrivenStateMachine`'s repository
lookups and `Services\GatePassageService`'s persistence) talk to
`EntityManagerInterface` through `findBy`/`findOneBy`/`QueryBuilder` only; nothing
in this package requires a schema migration or a running MySQL to validate its
logic.

## Requirements

- PHP **≥ 8.3**
- `doctrine/orm` **^3.0** (the package's one runtime dependency beyond `milpa/core`)
- `milpa/core` — `VerifierInterface`, the `VerificationRequest`/`VerificationContext`/
  `VerificationResult` value objects, and `Support\UuidGenerator`

## Documentation

**Full API reference: [getmilpa.github.io/workflow](https://getmilpa.github.io/workflow/)**
— generated straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=workflow)**.
