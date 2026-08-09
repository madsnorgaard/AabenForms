# Compliance pack (pre-sales / procurement)

Documents a Danish municipality's IT and DPO function needs before a pilot can
start (issue #92). A kommune cannot procure without a databehandleraftale and
cannot lawfully process without an Article 30 record; the DPIA is required on
its own because the platform processes custody data on children.

The documents are in Danish because their audience is the kommune's DPO,
jurist and informationssikkerhedskoordinator. They are drafts on the
platform's honest current state (see `../PROMISES-VS-VERIFIED.md`): where a
control is planned but not built, the document says so and references the
GitHub issue.

| Document | Purpose | Status |
|---|---|---|
| [DATABEHANDLERAFTALE.md](DATABEHANDLERAFTALE.md) | Data processing agreement following the structure of Datatilsynet's standardkontraktsbestemmelser (art. 28(3)), with bilag A-D filled in for AabenForms | Draft - parties, CVR and signatures to be completed per kommune |
| [FORTEGNELSE_ART30.md](FORTEGNELSE_ART30.md) | Record of processing activities: art. 30(2) as databehandler, plus the kommune's art. 30(1) input per workflow | Draft - reusable across kommuner |
| [DPIA_KONSEKVENSANALYSE.md](DPIA_KONSEKVENSANALYSE.md) | Data protection impact assessment (art. 35): CPR, children's and custody data, MitID identity, Digital Post | Draft - risk table reflects verified platform state |
| [NIS2_INCIDENT_RUNBOOK.md](NIS2_INCIDENT_RUNBOOK.md) | Incident response runbook with the NIS2 and GDPR art. 33/34 notification deadlines | Draft - contact table to be completed per kommune |

Out of scope here: tilgaengelighedserklaeringen (accessibility statement)
depends on the frontend axe/WCAG audit landing first, tracked in
madsnorgaard/aabenforms-frontend#25.

## How to use in a procurement conversation

1. Send the DPA draft and the Art. 30 record with the first technical
   material; they answer the DPO's first two questions unprompted.
2. The DPIA is a working document: the kommune is dataansvarlig and owns the
   final DPIA, but arriving with a substantially complete draft moves the
   conversation from "can you?" to "when do we start?".
3. The NIS2 runbook doubles as the operational annex the kommune's
   informationssikkerhedsudvalg will ask for.

## Maintenance

Keep these synchronized with reality. When a security issue closes (e.g. #91
retention, #142 rate limiting), update the affected rows in the DPIA risk
table and the security annex in the DPA. A compliance document that overstates
the platform is worse than none - it is exactly what a municipal evaluator
checks first.
