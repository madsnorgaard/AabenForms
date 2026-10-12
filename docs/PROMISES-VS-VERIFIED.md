# Promises vs Verified reality

A deliberate, honest record of what AabenForms **claims** versus what is **verified working**, produced from a multi-agent local security pressure test (every finding reproduced by an independent adversarial pass). Kept in the repo so marketing copy and demos stay truthful in front of a municipal evaluator.

Status key: **WORKS** (verified) · **PARTIAL** · **BROKEN** (verified defect) · **MISSING** · **UNVERIFIED** · **FABRICATED**.

| Claim | Status | Notes / fix |
|---|---|---|
| Visual workflow modeler, ready-made flows | **WORKS** | 18 ECA flows render in the React-Flow Workflow Modeler with execution replay; zero console errors. (Marketing "7"/"13 BPMN templates" count and "BPMN 2.0 designer" wording are inaccurate - it is the Modeler API / `workflow_modeler`.) |
| Parent-CPR consent gate ("only the right parent can approve", #54) | **BROKEN → fixed** | Was inert (no parent CPR collected → always `missing_expected_cpr`) and the controller failed **open**; session flag not submission-scoped. Closed in the consent-gate-hardening PR (fail-closed, real CPR fields, scoped session, submit-time re-verify). |
| MitID authentication | **BROKEN → fixed** | `MitIdValidateAction` reported "verified" with no session ("demo mode"); the result token was never gated. Now fails **closed** by default behind `allow_mitid_demo_mode`. |
| CPR/CVR lookup via Serviceplatformen (SF1520/SF1530) + audit "resolved against registry" | **BROKEN → fixed** | `getTokenValue()` looked up the literal `[token]` string, so the lookups never called Serviceplatformen yet logged success. Token resolution fixed; lookup steps now record `skipped`/`failed` honestly. |
| Digital Post (SF1601) | **PARTIAL** | Sends in test modes; an empty/wiped `test_mode` now fails **closed** (throws) instead of faking a send (July 2026). Recipient CPR is still taken from the submitted form value and there is no idempotency (duplicate letters); recipient-from-verified-session + idempotency + failure-gating before case close are tracked follow-ups (pressure-test C1/H4/M2). |
| Execution replay / "full auditability" | **WORKS, hardened** | Real and useful; but while armed it recorded citizen CPR/IP into a cross-user store. Least-privilege + cron self-heal landed; deep redaction needs an upstream `eca` patch. |
| Role-based access control | **PARTIAL → improving** | `parent_request_form` was anonymous-creatable (now case-worker only); `administer workflows` is referenced by routes but defined nowhere (tracked). |
| "Field-level CPR encryption" / "deletion after case closure" | **PARTIAL** | Encryption-at-rest now fails **closed**: `CprAccess::protect()` throws if the key is missing (July 2026) instead of silently storing plaintext, so a CPR is never written unencrypted. No retention/erasure subsystem yet (issue #91); do not claim deletion until built. |
| Lawful case lifecycle (FVL §19/§25, allowed transitions) | **WORKS, hardened** | Enforced at the entity level (`AabenformsCase::preSave()`, July 2026), not only in the ECA actions: illegal transitions throw, a closed case is immutable, every update mints an audited revision, and the status field is no longer editable via the entity form. An adverse decision requires partshøring `afsluttet` or a recorded §19 stk. 2 exemption. |
| Statutory deadlines (frister) | **WORKS** | The frist clock computes working days in Europe/Copenhagen and skips Danish public holidays (store bededag correctly excluded); due normalised to end of day (July 2026). |
| Economic free-place (fripladstilskud) identity | **BROKEN → fixed** | The flow auto-decided and sent Digital Post on a self-reported, **unverified** CPR (no MitID gate). A MitID identity gate now runs before any processing (July 2026). |
| ESDH handoff (SBSYS/WorkZone/Acadre/GetOrganized) | **PARTIAL (scaffold)** | `aabenforms_esdh` journalises a case into the ESDH via a pluggable connector; demo driver only. Live connectors are production-shaped stubs that fail hard until configured. No transactional outbox, document assembly, or live transport yet (#84/#86). |
| WCAG 2.1 AA, "88% / 156 tests" | **UNVERIFIED** | No accessibility audit run; coverage figure unmeasured. Run axe-core/Lighthouse + measure before claiming. |
| Resilience to a Serviceplatformen outage | **BROKEN → fixed** | Was ~36s per request with no breaker. Now a per-service circuit breaker (#72): after 5 availability failures calls fail at once for 30s, then one failure on probation reopens it. 3s connect timeout, 15s total, timeouts are not retried, a refused connection is retried once, Digital Post sends never. Worst case per call ~7s refused / 15s hanging, until the breaker opens. Kernel test `ServiceplatformenCircuitBreakerTest`. |
| "Production Ready 2.0.0" | **NOT YET** | Pre-pilot. Several critical security fixes were required first; treat as in active hardening. |
| Municipality deployments (Vejle/Randers/Aalborg), satisfaction %, "450,000 forms", ROI (867%, €127k …) | **FABRICATED** | No such deployments. Removed/marked illustrative. Never present as real to a prospect. |
| Licence | **GPL-2.0-or-later** | Single source of truth. (Earlier EUPL-1.2 references were inconsistent and have been corrected.) |

Security hardening is being delivered as a staged sweep of PRs; this table is updated as each lands.
