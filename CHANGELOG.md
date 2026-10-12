# Changelog

All notable changes to the ÅbenForms backend. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/) as described in
[docs/RELEASING.md](docs/RELEASING.md).

Security fixes start with **[SECURITY]** so a kommune can find them by searching for that
prefix. A deploy note lists every manual step a release needs (environment variables, Drush
commands, config the deploy does not import).

## [Unreleased]

### Security
- [SECURITY] The MitID session id no longer reaches browser JavaScript. Server-side callers
  send it as `Authorization: Bearer`, the frontend keeps it in an HttpOnly cookie, and
  session ids are refused in URLs. Submissions are bound to the verified session (#252, #255,
  #257).
- [SECURITY] Users are bound to their tenant, and the binding is enforced on inboxes, records
  and case pages. Audit log and trace are scoped by tenant (#141, #174).
- [SECURITY] Per-flow NSIS assurance level enforced at the MitID gate, and the eIDAS level
  checked on the id_token (#157).
- [SECURITY] Only a party to a case can appeal it (#153).
- [SECURITY] MitID login refuses the mock IdP unless the site is a declared demo site
  (`AABENFORMS_DEMO_SITE=1`). Seeded sessions and approval-token minting work only in DDEV
  (#259).
- [SECURITY] The skoleleder list is scoped to the leader's own school (#255).
- [SECURITY] Digital Post letters never contain CPR (#261).
- [SECURITY] Drupal core 11.3.16 and then 11.3.17, ECA 3.1.4, webform 6.3.1, externalauth
  2.0.13 and symfony/cache 7.4.14 (#190, #228).
- [SECURITY] Casework lifecycle and identity/CPR handling fail closed (#162).

### Added
- NemLog-in OIOSAML 3 Service Provider (`aabenforms_nemlogin`) on provider-neutral identity
  contracts.
- Live SF1601 transport with real MeMo XML, Beskedfordeler delivery receipts, and a resilience
  queue (advancedqueue) with retry, backoff and dead-letter (#175, #188).
- ESDH connector module with SBSYS, WorkZone, Acadre and GetOrganized drivers, and a live
  SBSYS connector over SBSIP REST (#163).
- Custody, co-signature and dual-guardian Digital Post for school and family flows (#187).
- Demo scenarios: prefill provenance, sygedagpenge, skoleskift through to the school leader's
  decision, and MitID logout (#254).
- Retention and erasure subsystem, MitID guard, accessibility statement, fuldmagt and physical
  post (#259). Retention ships disabled.
- Rejection letters carry the reason and a klagevejledning (#251, #263).
- Compliance pack: DPA, Art. 30 record, DPIA and NIS2 runbook (#92).
- Danish admin interface through locale, with module .po files imported on deploy (#198).
- One-command tenant provisioning, evidence dashboard, readiness board and flow overview grouped
  by citizen journey (#200, #204).

### Changed
- Serviceplatformen client: per-service circuit breaker, 3 s connect timeout, no retry on a
  timeout, and Digital Post sends never retried (#72).
- Keycloak 26.8 in production mode on Postgres, with one theme source (#223, #266).
- Domain 3.0 and dependency updates through Dependabot.
- CLAUDE.md and the mock-services guide match the code; the guide states that the
  Serviceplatformen SOAP dialect is a placeholder, not the KOMBIT protocol (#171).
- Versioning policy, release steps and rollback in `docs/RELEASING.md`; this changelog (#149).
- Honeypot keeps its bot trap but drops the minimum-time check on the gated decision forms.

### Fixed
- Deleting a wizard-built ECA flow outside the wizard no longer leaves a dead entry in the
  dashboard and the template browser (#199).
- Wizard: AJAX race lockout, no silent webform preselect, honest step numbers (#196, #197).
- The dashboard counts real MitID logins and enabled ECA flows (#191, #192).
- One shared is-CPR authority (#172).
- api.aabenforms.dk deploys restored: xsl extension, MariaDB 11 (#220).

### Deploy notes
- Set `AABENFORMS_DEMO_SITE=1` on the demo site only. A kommune site must never set it.
- Run `docker compose restart keycloak` after a Keycloak theme change.

## [0.4.0] - 2026-06-18

Open casework engine and security hardening.
[Release notes](https://github.com/madsnorgaard/aabenforms/releases/tag/v0.4.0).

### Security
- [SECURITY] MitID `id_token` signature verified against the issuer JWKS, which closes an
  identity-forgery hole (#124).
- [SECURITY] `/mitid/session` masks CPR, audits reads and takes CPR from the session (#126).
- [SECURITY] Fixed the dead `caseworker_review_flow` gate (#123).

### Added
- `aabenforms_case`: case entity with a lawful lifecycle and a frist clock. FVL §19/§25 are
  enforced in code (#132).
- Flows: underretning, fripladstilskud, appeal and genvurdering, merudgifter SEL §41 (#128,
  #130, #136, #140).
- Demo integrations: eIndkomst, SF1470, SF2900 (#133, #134, #137).

### Deploy notes
- Requires `AABENFORMS_CPR_KEY` on the host.

## [0.3.0] - 2026-06-06

ECA identity gating, CPR encryption and identity binding.
[Release notes](https://github.com/madsnorgaard/aabenforms/releases/tag/v0.3.0).

### Security
- [SECURITY] Every identity step gates the flow; a failed MitID check stops it (#96).
- [SECURITY] CPR encrypted at rest with AES-256 (#98, #114).
- [SECURITY] Mileage and HR flows bound to the authenticated employee (#101).
- [SECURITY] The audit log records the real step outcome (#97, #117).
- [SECURITY] The CVR lookup no longer leaks a raw connection error (#99).
- [SECURITY] The parent consent gate compares the decrypted parent CPR (#116).

### Deploy notes
- Set `AABENFORMS_CPR_KEY` (base64 of 32 random bytes), then run `drush updatedb` and
  `drush config:import`.

## [0.2.1] - 2026-06-06

Visual workflow modeler, security hardening and an honest demo.
[Release notes](https://github.com/madsnorgaard/aabenforms/releases/tag/v0.2.1).

### Security
- [SECURITY] The consent gate fails closed and is scoped to the submission (#68).
- [SECURITY] CPR and CVR lookups actually run, and MitID validation fails closed (#69).
- [SECURITY] Execution replay permissions reduced, and cron disarms a forgotten replay capture
  (#70).

[Unreleased]: https://github.com/madsnorgaard/aabenforms/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/madsnorgaard/aabenforms/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/madsnorgaard/aabenforms/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/madsnorgaard/aabenforms/releases/tag/v0.2.1
