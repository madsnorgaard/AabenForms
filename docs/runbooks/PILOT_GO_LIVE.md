# Pilot go-live runbook

What has to be true before a kommune puts AabenForms in front of real citizens with real data. It replaces the older `PILOT_DEPLOYMENT_GUIDE.md` and `PRODUCTION_CHECKLIST.md` as the short, current list; those remain as background.

Most of the work is agreements, certificates and decisions, not code. Each item names its owner (who can actually do it) and the issue that tracks it. Tick an item only when it is done and verified, not when it is planned.

## 1. Agreements (the kommune and AabenForms)

- [ ] **Databehandleraftale signed.** Draft: `docs/compliance/DATABEHANDLERAFTALE.md`. Owner: kommune jurist and AabenForms.
- [ ] **DPIA owned by the kommune.** Draft: `docs/compliance/DPIA_KONSEKVENSANALYSE.md`. The kommune is dataansvarlig and approves the final version. Owner: kommune DPO.
- [ ] **Art. 30 record updated** per workflow in the pilot (`docs/compliance/FORTEGNELSE_ART30.md`). Owner: kommune DPO.
- [ ] **Retention periods set** per workflow from the kommune's kassationsbestemmelser (config `aabenforms_core.retention`, see `docs/compliance/RETENTION.md`). The shipped values are placeholders. Owner: kommune DPO and arkivansvarlig. Issue #91.
- [ ] **KL Blanketsamling subscription** confirmed, if the pilot replaces a KL blanket. Owner: kommune.

## 2. Identity: NemLog-in (MitID for citizens)

Public authorities must use NemLog-in as the MitID broker over OIOSAML 3. The OIDC rail against Keycloak is for development and the public demo only.

- [ ] **Service provider registered** in NemLog-in for the kommune's AabenForms instance (integrationstest first, then production). Owner: kommune with AabenForms. Issue #79.
- [ ] **OCES3 systemcertifikat (FOCES3)** for signing and decryption, ordered via MitID Erhverv, per environment. Note the expiry date in the renewal calendar (#143).
- [ ] **`aabenforms_nemlogin` configured and enabled.** Metadata, certificate as a Key entity, and the required assurance level (Betydelig / Substantial). Verify login end to end in integrationstest.
- [ ] **No mock IdP settings in production.** The MitID login refuses mock settings outside DDEV unless `AABENFORMS_DEMO_SITE=1`. That variable must not be set on a kommune site. Check the Drupal status report shows no "MitID settings" error.

## 3. Registers and Digital Post: Serviceplatformen

- [ ] **Serviceaftaler approved by the kommune** for each service the pilot uses: CPR (SF1520 or its successor), CPR Familie+ (SF6006) where custody matters, CVR (SF1530), Digital Post (SF1601) and Beskedfordeler (SF1461). Owner: kommune. Issue #76.
- [ ] **FOCES3 certificate for Serviceplatformen** per environment, stored as a Key entity, never in git.
- [ ] **Each integration verified in the test environment, then switched from DEMO to LIVE** in config. The demo pages' status labels must change with it.
- [ ] **Digital Post sender** is the kommune's CVR. Test a letter end to end, including the delivery receipt via Beskedfordeler.
- [ ] **Digital Post fritagelse handled** for the pilot's letters: physical post for exempt citizens (#242).

## 4. Case system (ESDH) and archive

- [ ] **The kommune's ESDH connector configured** (SBSYS, GetOrganized, WorkZone or Acadre). Journalise a test case end to end. Issues #85, #86.
- [ ] **SF2900 distribution with asynchronous receipts**, if the pilot hands cases to a fagsystem (#155).
- [ ] **Confirm that the ESDH is the system of record**, so AabenForms' intake copies can be deleted after the retention period.

## 5. Security

The security programme is #252. Phase 1 (submit binding, no session ids in URLs, rate limiting, replay redaction, demo isolation) is in #257.

- [ ] **Environment secrets set on the server:**
  - `AABENFORMS_CPR_KEY` per tenant (base64 of 32 random bytes);
  - the NemLog-in / Serviceplatformen keys;
  - `CORS_ALLOW_ORIGIN` set to the kommune's frontend origin only.
- [ ] **`AABENFORMS_DEMO_SITE` is not set**, and no demo data is seeded.
- [ ] **Rate limits reviewed** for the kommune's traffic (config `aabenforms_core.rate_limits`).
- [ ] **External penetration test** done, with findings fixed or accepted in writing.
- [ ] **Backups and restore tested.** The deploy takes a database snapshot before `updb` / `cim`; also test a restore.
- [ ] **Incident runbook contacts filled in** (`docs/compliance/NIS2_INCIDENT_RUNBOOK.md`).

## 6. Accessibility

- [ ] **The kommune's own tilgængelighedserklæring** on was.digst.dk links to AabenForms' statement (`docs/compliance/TILGAENGELIGHEDSERKLAERING.md`, https://aabenforms.dk/tilgaengelighed). Issue #247.
- [ ] **Manual screen reader and keyboard test** of the pilot's journeys, with the statement updated.
- [ ] **Caseworker UI audited**, if caseworkers with assistive technology are in the pilot (#245).

## 7. The day before

- [ ] **Full Playwright and axe run** against the pilot environment's own build.
- [ ] **Status report** at `/admin/reports/status` has no errors.
- [ ] **A real test citizen** (a kommune employee) completes each pilot journey and receives the letter.
- [ ] **Support channel staffed**, with the reply time stated in the accessibility statement (10 arbejdsdage) and on the contact page.
