# Resilience track: durable, retryable workflow execution

## Why

Today ECA flows run **synchronously inside the citizen's submit request**
(`$submission->save()` drives the whole flow), and there is **no queue, no
automatic retry, and no dead-letter**. A slow or down external system
(Serviceplatformen, SBSYS) blocks the citizen; a transient failure leaves the
case in place but requires a human to re-fire the flow. The correctness
primitives for resilience already exist - non-throwing actions, transient vs
permanent classification, idempotency keys, deadline surfacing, async receipt
reconciliation - but the **automation** does not. This track adds it while
keeping every ECA rule (dual-gate deny, lawful `allowedTransitions`, FVL/RSL
frist, the status-token contract) intact.

## The load-bearing property (already delivered)

Safe retry needs idempotency + a truthful outcome. This is now in place:

- **Idempotency keys everywhere**: `transaction_id` unique key (Digital Post),
  `esdh_ref` + SBSYS case-UUID external key (ESDH), `digital_post_tx` on the
  case. A re-run does not double-send a letter or double-open a case.
- **Three-valued outcomes threaded through consumers** (the HIGH-finding fixes):
  `Result::pending` / `EsdhResult::$transient` now reach the audit trail and the
  status token, so an accepted-but-not-delivered live send is `pending`, not a
  false `failure`, and the flow advances instead of looking stuck.

So R0 is done. The rest is the queue mechanism and the operability layer.

## Sequenced phases

**R1 - Queue infrastructure (advancedqueue), one integration first.**
Add `drupal/advancedqueue`. Define one queue per integration class
(`digital_post`, `esdh`, later `cpr_lookup`) with a per-queue retry policy (max
attempts + exponential backoff). Add one advancedqueue `Job` per integration
that calls the EXISTING service (`LiveSf1601Client`, `SbsysEsdhConnector`) -
which are already idempotent and transient-aware - and maps the outcome:
transient -> throw a retryable exception (advancedqueue backs off + retries);
permanent -> move to dead-letter; success/pending -> record the outcome + set
the same tokens/case fields the synchronous path sets. Pilot with Digital Post.

**R2 - Async submit (decouple the citizen from the backend).**
Keep the citizen-facing gates synchronous (identity validation, the deny
terminal, form validation - they must return in the request), but ENQUEUE the
slow external side-effects (Digital Post, ESDH, register lookups) instead of
calling them inline. The action records a `queued` step; a follow-up flow
triggered on job completion advances the case. Net effect: the citizen's submit
no longer blocks on Serviceplatformen/SBSYS uptime.

**R3 - Dead-letter + human handoff.**
A dead-letter state for terminally-failed jobs, surfaced in the caseworker inbox
as a "needs attention" filter beside the frist clock, with the evidence trace
showing why it failed. A caseworker can re-drive (re-enqueue) or handle manually.
The case stays in its lawful state; delivery/journal fields reflect
`failed`/`pending`; nothing auto-closes on failure.

**R4 - Reconciliation cron.**
For `pending` states awaiting an external callback (the Digital Post receipt), a
cron reconciler ages-out stale pendings (no receipt after N hours -> flag for
review). The Beskedfordeler push receipt already reconciles the happy path; the
cron catches the ones that never get a receipt.

**R5 - Rules preserved under async (the acceptance criterion).**
Every ECA rule must still apply when work is queued. It does, because the queued
jobs call the SAME services, record the SAME steps, set the SAME status tokens
and case fields, and the lawful-transition guard lives in
`AabenformsCase::preSave()` (`allowedTransitions`) - independent of sync/async.
Idempotency + "never close on a transient failure" mean a retry cannot
double-apply or wrongly advance a case. A queue-integration test asserts: a
transient job retries then succeeds without a second letter; a permanent job
dead-letters and the case is NOT closed; a completed job advances the flow
exactly as the synchronous path did.

## Order of work

R0 (the four HIGH fixes) -> R1 with Digital Post as the pilot integration ->
R2 async submit for that integration -> R3 dead-letter + inbox -> R4 reconciler
-> generalise R1/R2 to ESDH and the register lookups. Each phase ships behind
config and keeps the synchronous demo path working until the async path is
proven.
