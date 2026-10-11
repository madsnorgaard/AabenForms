# Opbevaring, sletning og retten til sletning

Status: Implementeret i kode (issue #91), ikke verificeret i drift. Fristerne er
pladsholdere, som kommunens DPO skal fastsætte, før platformen tages i brug med
rigtige borgerdata.

Målgruppe: kommunens databeskyttelsesrådgiver (DPO), jurist og driftsansvarlig.

## Grundprincip

AabenForms er ikke kommunens system of record. Myndighedens sag er den
journaliserede sag i kommunens ESDH-system og Sags- og Dokumentindekset
(SF1470). Det, der ligger i AabenForms, er en modtagekopi: borgerens
indsendelse, den sag platformen har åbnet ud fra den, og spor af behandlingen.
Kopien må ikke leve længere end formålet (GDPR art. 5, stk. 1, litra e), men den
må heller ikke forsvinde, før sagen findes et andet sted (arkivloven og
journaliseringspligten efter offentlighedsloven).

Derfor sletter platformen aldrig en sags data, før sagen er:

1. **lukket** (status `lukket`), og
2. **journaliseret** i kommunens ESDH (sagen har en ESDH-reference
   `esdh_ref`, så selve akten findes i myndighedens system). En reference i
   SF1470-indekset (`journal_ref`) tæller kun, hvis DPO'en sætter
   `require_esdh_ref: false`, og
3. **ikke omfattet af retlig tilbageholdelse** (legal hold).

En indsendelse, der aldrig blev til en sag, slettes efter en kortere frist.

## Hvad slettes, og hvad bevares

| Data | Hvad sker der | Hvornår |
|---|---|---|
| Sagen (`aabenforms_case`) med alle revisioner | Slettes helt | `default_days_after_close` dage efter lukning (eller formularens egen frist) |
| Indsendelser knyttet til sagen (via `submission_ref` på en hvilken som helst revision, eller via et `case_id`-felt som på klageformularen) og deres feltdata, inkl. krypteret CPR og uploadede filer | Slettes helt | Sammen med sagen |
| Sporingsrækker (`aabenforms_trace`) for sagen og dens indsendelser | Slettes | Sammen med sagen |
| Digital Post-log for breve sagen har sendt (via `digital_post_tx`) | Brevtekst, MeMo-XML (indeholder modtagerens CPR i klartekst), transportsvar, emne og modtager-hash fjernes. Rækken bevares med transaktions-id, status og kvitteringsfelter, fordi idempotens og leveringskvitteringer slår op på den | Sammen med sagen |
| Øvrige Digital Post-logrækker | Redigeres som ovenfor | `digital_post_log_days` dage efter afsendelse, uanset sag (loggen har ingen sagskolonne) |
| Indsendelser, der aldrig blev til en sag | Slettes helt med sporingsrækker | `unlinked_submission_days` dage efter seneste ændring |
| Auditlog (`aabenforms_audit_log`) | Bevares; egen frist `audit.retention_days` (standard 5 år) | Ved cron |
| MitID-sessioner og login-handoff | Udløber af sig selv | 15 minutter |
| Løn- og valgtabeller (`aabenforms_payroll_log`, `aabenforms_election*`) | Ikke omfattet; de er ikke knyttet til indsendelser | Kommunens frist, manuel procedure |

Undtagelser, der altid bevares uanset alder:

- **Åbne sager** (alle andre statusser end `lukket`).
- **Lukkede, ikke-journaliserede sager.** Kopien kan være den eneste registrering.
- **Sager og indsendelser under retlig tilbageholdelse.** En tilbageholdt
  indsendelse holder også hele sin sag tilbage.
- **Indsendelser uden sag, der afventer en afgørelse**: hvor `caseworker_status`,
  `consent_status` eller `parent<N>_status` har værdien `pending`, `afventer`,
  `awaiting` eller `in_progress`. Myndigheden skal stadig handle på dem.
- **En klage, hvis `case_id` peger på en eksisterende sag**, følger sagen.

## Konfiguration

Politikken ligger i konfigurationen `aabenforms_core.retention`:

| Nøgle | Standard | Betydning |
|---|---|---|
| `enabled` | `false` | Om cron sletter. Slået fra, indtil DPO'en har fastsat fristerne: en sletning kan ikke fortrydes, så den slås aldrig til af en udrulning. Drush `--dry-run` og sletteanmodninger virker uanset |
| `require_esdh_ref` | `true` | Kun en ESDH-reference tæller som journaliseret. `false` godtager også SF1470-indeksreferencen alene |
| `default_days_after_close` | `90` | Dage efter lukning, før en journaliseret sags modtagekopi slettes |
| `webforms` | `[]` | Undtagelser pr. formular: `{id, days_after_close, unlinked_submission_days}`; tom værdi betyder den generelle frist |
| `unlinked_submission_days` | `30` | Dage efter seneste ændring, før en indsendelse uden sag slettes |
| `digital_post_log_days` | `180` | Dage efter afsendelse, før en Digital Post-logrække redigeres |
| `batch_size` | `50` | Højst så mange sletteenheder (en sag med indsendelser, eller en løs indsendelse) pr. cron-kørsel |

En værdi under 1 slår sletning fra for det område. Manglende konfiguration
betyder: intet slettes.

**Standardværdierne er pladsholdere.** De er sat ud fra, at ESDH er det
autoritative arkiv, og at modtagekopien kun skal overleve en kort
kontrolperiode. Kommunens DPO skal fastsætte de faktiske frister pr. sagstype
ud fra den gældende kassationsbestemmelse og kommunens egne
journaliseringsrutiner, og især vurdere `unlinked_submission_days` for
formularer, hvis forløb ikke åbner en sag. Fristerne noteres i
databehandleraftalens bilag C og i fortegnelsen efter art. 30.

## Hvordan sletning udløses

- **Cron**: hver cron-kørsel behandler én batch (`batch_size`). Et efterslæb
  afvikles over flere kørsler.
- **Drush**:
  - `drush aabenforms:retention:run --dry-run` viser antal og id'er på det, der
    ville blive slettet, og hvad der bevares og hvorfor. Intet ændres, og
    output indeholder ingen personoplysninger.
  - `drush aabenforms:retention:run` sletter én batch (`--limit=N` for en anden
    størrelse).
- Hver sletning sker i én databasetransaktion og skriver først en auditrække
  `retention_purged` (identifikator `case:<id>` eller `submission:<id>`,
  hashet; konteksten indeholder kun id'er og antal). Kan auditrækken ikke
  skrives, slettes intet.
- Hver sag vurderes igen inde i transaktionen, så en sag, der er genåbnet eller
  sat i tilbageholdelse et øjeblik før, ikke slettes.

## Retlig tilbageholdelse (legal hold)

Brug tilbageholdelse, når data skal bevares ud over fristen, fx ved en verserende
klage hos Ankestyrelsen, en retssag, en aktindsigtssag eller en
tilsynsundersøgelse.

```
drush aabenforms:legal-hold 42 --on --reason="Klage hos Ankestyrelsen, j.nr. 2026-123"
drush aabenforms:legal-hold submission:310 --on --reason="Aktindsigtssag"
drush aabenforms:legal-hold 42 --off --reason="Klagesag afgjort"
```

Tilbageholdelsen gemmes i en separat liste (Drupals keyvalue-lager), ikke på
selve sagen. En lukket sag kan ikke ændres, og en tilbageholdelse er ikke
sagsbehandling, så den skal hverken bryde den regel eller skabe en ny
sagsrevision. Både placering og frigivelse auditeres (`legal_hold`).

## Retten til sletning (GDPR art. 17): procedure for DPO

En offentlig myndighed behandler oplysninger for at opfylde en retlig
forpligtelse eller udøve offentlig myndighed (art. 6, stk. 1, litra c og e).
Art. 17, stk. 3, litra b, undtager oplysninger, som myndigheden skal bevare for
at overholde en retlig forpligtelse. Arkivloven og journaliseringspligten er
sådanne forpligtelser. I praksis betyder det:

- Selve sagen i ESDH slettes **ikke** via AabenForms. Det er kommunens
  arkivregler, der styrer den.
- AabenForms' modtagekopi af en **lukket og journaliseret** sag kan slettes med
  det samme, fordi den autoritative registrering findes i ESDH.
- En indsendelse, der **aldrig blev til en sag** og ikke afventer en afgørelse,
  kan slettes med det samme.
- En anmodning **afvises** for åbne sager, lukkede men ikke-journaliserede sager,
  data under tilbageholdelse og indsendelser, der afventer en afgørelse.

Procedure:

1. Modtag og registrér anmodningen i kommunens eget system (journalnummer).
2. Find sags-id eller indsendelses-id i sagsoversigten (`/admin/aabenforms/cases`).
3. Kør:

   ```
   drush aabenforms:erasure:request case:42 --reason="DPO-2026-17"
   drush aabenforms:erasure:request submission:310 --reason="DPO-2026-18"
   ```

   Angiv altid `case:` eller `submission:`, fordi sags- og indsendelses-id'er
   kan være ens. En indsendelse, der hører til en sag, behandles som sagen.
4. Ved afvisning afsluttes kommandoen med en fejlkode og en begrundelse med
   henvisning til art. 17, stk. 3, litra b, og arkivloven. Svar borgeren ud fra
   den, inden for fristen i art. 12, stk. 3 (en måned).
5. Alle anmodninger auditeres som `erasure_requested` med udfaldet (`purged`,
   `refused`, `not_found` eller `invalid`).

Skriv ikke personoplysninger i `--reason`. CPR-numre maskeres automatisk, men
navne og andre oplysninger gør ikke.

## Kendte begrænsninger

- Digital Post-loggen har ingen sags- eller indsendelseskolonne. Breve kobles
  kun til en sag via `digital_post_tx`; øvrige breve redigeres alene efter alder.
- "Afventer afgørelse" genkendes ud fra feltværdierne nævnt ovenfor. En formular,
  der bruger andre felter eller værdier til at markere et uafsluttet forløb, og
  som ikke åbner en sag, slettes efter `unlinked_submission_days`.
- Som standard regnes en sag kun som journaliseret med en ESDH-reference. Har
  kommunen ingen ESDH-integration, slettes sager derfor ikke automatisk, før
  DPO'en har taget stilling (`require_esdh_ref`).
- Auditloggen indeholder SHA-256 af identifikatorer og har sin egen frist.
- Drush-kommandoer registrerer bruger-id 0 og IP `CLI`. Den, der kører dem,
  skal fremgå af kommunens egen journal over anmodningen.
