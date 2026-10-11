# AabenForms Backend

Headless Drupal 11 backend for Danish municipal self-service: ECA workflows, prefill from public registers with provenance, MitID, Digital Post and casework.

[![Drupal](https://img.shields.io/badge/Drupal-11.3-blue)](https://www.drupal.org)
[![PHP](https://img.shields.io/badge/PHP-8.4-purple)](https://www.php.net)
[![License](https://img.shields.io/badge/License-GPL--2.0-green)](LICENSE.txt)
[![CI](https://github.com/madsnorgaard/AabenForms/actions/workflows/ci.yml/badge.svg)](https://github.com/madsnorgaard/AabenForms/actions/workflows/ci.yml)
[![Coding Standards](https://github.com/madsnorgaard/AabenForms/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/madsnorgaard/AabenForms/actions/workflows/coding-standards.yml)

## Status

Pre-pilot. Live at https://api.aabenforms.dk, with the Nuxt frontend at https://aabenforms.dk. The production site is single-tenant and holds no real citizen data.

- **Identity:** MitID runs against a mock IdP (Keycloak at auth.aabenforms.dk) until production registration is done.
- **Integrations:** none run live against government endpoints yet. Each has one of the statuses below.
- **Detail:** for a claim-by-claim account, see [docs/PROMISES-VS-VERIFIED.md](docs/PROMISES-VS-VERIFIED.md).

| Status | Meaning | Integrations |
|---|---|---|
| LIVE | AabenForms' own data | Case register, audit log, institution registry |
| LIVE-PARAT | Production code is built; it needs a certificate and an agreement | CPR SF1520, CVR SF1530, Digital Post SF1601 (MeMo + SOAP), Beskedfordeler SF1461, NemLog-in OIOSAML 3, SBSYS ESDH |
| DEMO | Production-shaped contract with demo data | CPR Familie+ SF6006, STIL pupil enrolment, eIndkomst / NemRefusion, SF1470, SF2900, other ESDH connectors, payment, SMS, GIS |
| Mock IdP | Real protocol, test identities | MitID (OIDC against Keycloak) |

## What it does

- **Flows are configuration, not code.** Workflows are ECA flows edited in the visual Workflow Modeler (`drupal/modeler`). There are 33 flows in `config/sync`, and every run can be inspected in the execution replay.
- **Known data is not asked again.** `GET /api/prefill/{webform}` runs the register lookups the flow would run. Each value comes back with its source register, contract, legal basis, mode and an audit id, and the CPR is always masked. The frontend shows this as a provenance panel.
- **Casework end to end.** A case (sag) has a lawful status lifecycle that the entity enforces itself. Its deadline (frist) clock knows Danish holidays and calendar weeks. The flows cover partshøring (FVL §19), decisions with klagevejledning and a computed klagefrist (§25), letters via Digital Post, appeals, ESDH journaling and SF2900 distribution.
- **Two-party consent.** Skoleskift and similar flows need both custody holders to sign with their own MitID. A staff decision step follows, scoped to the decision maker's own institution through the org chart.
- **Field-level CPR encryption** (AES-256) and GDPR audit logging, both in `aabenforms_core`. A CPR submission fails hard if the key is missing rather than storing plaintext.

### Demo scenarios

These run with the frontend at https://aabenforms.dk/demo:

| Scenario | Flow(s) | What is fetched instead of asked |
|---|---|---|
| Byggetilladelse | `building_permit_flow` | Name, CPR, address |
| Merudgifter (SEL §41) | `merudgifter_flow` | The applicant's children. Income is deliberately not fetched |
| Skoleskift | `skoleskift_flow`, `skoleskift_cosign_flow`, `skoleskift_decision_flow` | Custody, both parents' CPR and the child's current school. The school leader decides at `/sagsbehandling/skoleskift` |
| Klage | `klage_flow` | The citizen's cases, the decision and the klagefrist |
| Sygedagpenge | `sygedagpenge_flow` | Employer and working hours |

Seed and reset demo data with `drush aabenforms:demo:seed-klage-case [--reset]`. Create the school leader account with `drush aabenforms:demo:ensure-skoleleder`. Personas live in the mock realm (`.ddev/mocks/keycloak/realms/danish-gov-test.json`) and in `aabenforms_mitid/src/DemoPersonas.php`.

## Security model

- **Session ids never travel in URLs.** The MitID session id is a bearer capability. Prefill, the session inbox and the co-sign receipt read it only from `Authorization: Bearer` (`aabenforms_core/src/Http/SessionBearer.php`).
- **Logout ends the IdP session too.** It revokes the MitID session and performs RP-initiated logout with an `id_token_hint`.
- **Family CPRs are resolved on the server.** A child's and the other parent's CPR come from the family register at submit time, never from the client.
- **The co-sign binds MitID on the server.** It reads the minted session from the browser's Drupal session, consumes it once, and re-checks the CPR.
- **Seeded demo sessions only work on DDEV.**

The open items, in order, are tracked in the security programme [#252](https://github.com/madsnorgaard/AabenForms/issues/252). The most important:
- binding the applicant CPR and register-derived facts at submit;
- removing the session id from the MitID callback URL (#156);
- rate limiting the remaining routes (#142);
- retention and erasure (#91).

## Architecture

```
Nuxt 3 frontend  ->  Drupal 11 backend (ECA, Webform, JSON + JSON:API)  ->  Danish public services
(aabenforms.dk)      (api.aabenforms.dk)                                   MitID / NemLog-in, Serviceplatformen,
                                                                           Digital Post, KOMBIT, ESDH
```

Merging to `main` deploys. The deploy is orchestrated by [contabo-infrastructure](https://github.com/madsnorgaard/contabo-infrastructure): it snapshots the database, then runs `drush updb` and `drush cim`. Demo seed commands are run by hand.

## Modules

| Module | What it does |
|---|---|
| `aabenforms_core` | Serviceplatformen client, prefill with provenance, legal-basis map, family / enrolment / employment lookups (live-parat or demo), CPR encryption, audit logging, `SessionBearer` |
| `aabenforms_workflows` | ECA action plugins, parent approval and co-sign, skoleskift receipt and school leader pages, org chart, template wizard |
| `aabenforms_mitid` | MitID OIDC (PKCE), session store, CPR claim extraction, revoke and RP-initiated logout, demo personas |
| `aabenforms_nemlogin` | NemLog-in OIOSAML 3 service provider for production citizen login (built, not enabled) |
| `aabenforms_webform` | Webform elements with server-side validation: CPR (modulus 11), CVR, Adressevælger |
| `aabenforms_case` | Case entity, lifecycle, frist clock, decision / appeal / journal actions, case lookup, klage demo seeder |
| `aabenforms_kombit` | SF2900 Fordelingskomponent distribution (demo contract) |
| `aabenforms_esdh` | ESDH journaling with pluggable connectors: SBSYS (REST), plus demo, GetOrganized, WorkZone and Acadre |
| `aabenforms_institution` | Institution registry (Institutionsregisteret), leaders and hierarchy-aware routing |
| `aabenforms_tenant` | Domain-based multi-tenancy (production runs single-tenant) |
| `aabenforms_digital_post` | SF1601 core with fake_db, wiremock and live MeMo + SOAP transports; submodules `_eca` (actions), `_queue` (retry and dead letter), `_beskedfordeler` (SF1461 receipts), `_session_inbox` (citizen inbox for the demo) |

Contrib core: Drupal 11.3.x (pinned to the 11.3 line on purpose), ECA 3.1.x, Workflow Modeler 1.0.x with Modeler API 1.1.x, Webform 6.3.x.

## Quick start

```bash
git clone https://github.com/madsnorgaard/AabenForms.git backend
cd backend
ddev start
ddev drush uli
```

`ddev start` also boots Keycloak (mock MitID, http://localhost:8080) and WireMock (mock Serviceplatformen). The site is at https://aabenforms.ddev.site and mail at `:8026`. See [docs/DDEV_MOCK_SERVICES_GUIDE.md](docs/DDEV_MOCK_SERVICES_GUIDE.md).

## Development

```bash
ddev drush cr
ddev exec 'vendor/bin/phpunit -c phpunit.xml --testsuite unit,kernel'
ddev exec 'vendor/bin/phpcs --standard=Drupal --warning-severity=0 web/modules/custom'
```

- **Importing config locally:** never run a full `drush cim` against the local demo database, because it drifts. Import single files with `.ddev/partial-import.php` instead.
- **Where things are:**
  - flows at `/admin/config/workflow/eca`, with "Review flow" for the execution replay;
  - the template wizard at `/admin/aabenforms/workflow-templates`;
  - the case inbox at `/admin/aabenforms/cases`.
- **Further reading:** [docs/WORKFLOW_GUIDE.md](docs/WORKFLOW_GUIDE.md) and [docs/DATA-FLOW.md](docs/DATA-FLOW.md), which covers the PII sinks and trust boundaries.

CI runs PHPUnit (it fails the build), PHPCS on the whole custom tree (errors only), PHPStan level 6 (advisory), Composer validation and the Docker image build.

## Roadmap

The queue is in [GitHub issues](https://github.com/madsnorgaard/AabenForms/issues), sorted by `priority:*` labels:

- **Before any real citizen data (critical / high):**
  - the security programme #252 and #156;
  - binding identity at submit (#73, #251);
  - redaction in the replay store (#74);
  - retention and erasure (#91);
  - rate limiting (#142).
- **Going live:**
  - Serviceplatformen certificates (#76);
  - MitID / NemLog-in registration (#79);
  - the tilgængelighedserklæring (#247);
  - Digital Post fritagelse (#242);
  - the async SF2900 receipt (#155).
- **Digital inclusion** (label `digital-inklusion`): acting on behalf of a citizen (#241), save and resume (#244), plain language (#243), STIL live (#253), inclusion metrics (#246).
- **Flows:** SLA and escalation (#87), Ankestyrelsen forwarding (#89), gateways (#88), history (#90).

## Related

- Frontend: [aabenforms-frontend](https://github.com/madsnorgaard/aabenforms-frontend) (Nuxt 3, DKFDS)
- Deployment: [contabo-infrastructure](https://github.com/madsnorgaard/contabo-infrastructure)

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
