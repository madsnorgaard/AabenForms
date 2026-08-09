# Konsekvensanalyse vedrørende databeskyttelse (DPIA)

**AabenForms - digital selvbetjening og sagsgange for [Kommune]**

Udkast til brug for kommunens DPIA efter databeskyttelsesforordningens
artikel 35. Kommunen er dataansvarlig og ejer den endelige analyse; dette
dokument leverer den systematiske beskrivelse, leverandørens
risikovurdering og de tekniske foranstaltninger, så kommunens DPO kan
færdiggøre analysen uden at starte forfra. Risikotabellen afspejler
platformens **verificerede** tilstand (jf. `../PROMISES-VS-VERIFIED.md`) -
ikke marketingmateriale.

## 1. Er en DPIA påkrævet?

Ja. Behandlingen rammer flere af kriterierne i Datatilsynets/EDPB's liste
over behandlinger med sandsynlig høj risiko:

- **Oplysninger om børn** behandles systematisk (skoleskift, PPR,
  underretninger, merudgifter), og børn er sårbare registrerede.
- **Følsomme oplysninger (art. 9)**: helbred/funktionsnedsættelse i
  merudgifts- og PPR-forløb; socialt belastende indhold i underretninger.
- **CPR-numre** (national identifikator, dbl. § 11) i næsten alle forløb.
- **Forældremyndigheds- og familierelationer**, herunder konfliktfyldte
  (dobbelt-forældre-godkendelse), hvor fejl kan have alvorlige konsekvenser.
- Behandlingen sker i stor skala for en kommunes borgere og kombinerer
  registre (CPR-opslag, MitID-identitet, Digital Post).

## 2. Systematisk beskrivelse af behandlingen (art. 35, stk. 7, litra a)

### 2.1 Dataflow (resumé; detaljeret sporing i `../DATA-FLOW.md`)

1. Borgeren udfylder en formular i den borgervendte frontend; ved
   identitetskrævende forløb gennemføres MitID-login først (OIDC).
   MitID-sessionen lever server-side med 15 minutters TTL; frontend ser kun
   maskeret CPR (DDMMÅÅ-XXXX).
2. Indsendelsen modtages af backend-API'et, hvor identitet håndhæves
   server-side, og CPR-felter krypteres feltnært (AES-256) **før** lagring.
   Kryptering er fail-closed: kan der ikke krypteres, afvises indsendelsen
   frem for at gemme klartekst.
3. ECA-arbejdsgange udfører sagsskridt: registeropslag (SF1520/SF1530 via
   Serviceplatformen, når kommunens tilslutning er aktiv), samtykkegates
   (forældre-CPR verificeres mod MitID-session, fail-closed), fristberegning
   (danske helligdage, Europe/Copenhagen), afgørelsesbreve.
4. Kommunikation til borgeren sendes som Digital Post (SF1601).
   Sagen afleveres til kommunens ESDH/fagsystem.
5. Al adgang til personoplysninger auditlogges (bruger, handling, formål,
   status, IP, SHA-256-hashet identifikator); retention 5 år, automatisk
   oprydning via cron.

### 2.2 Systemkarakteristika med databeskyttelsesrelevans

- Headless Drupal 11-backend, Nuxt-frontend; åben kildekode
  (GPL-2.0-or-later), hvilket muliggør uafhængig revision.
- Multi-tenant-arkitektur med tenant-specifikke krypteringsnøgler; en
  kommunes nøgle kan ikke afkode en anden kommunes CPR-data.
- Afgørelsesbærende felter (fx caseworker_status) kan ikke sættes af
  borgeren; guard håndhæves server-side.
- Lovlige sagstilstandsovergange håndhæves på entitetsniveau: en afsluttet
  sag er uforanderlig, en bebyrdende afgørelse kræver gennemført partshøring
  (forvaltningsloven § 19) eller registreret undtagelse.

## 3. Nødvendighed og proportionalitet (art. 35, stk. 7, litra b)

- **Dataminimering:** Formularer indsamler kun felter, som det konkrete
  forløb kræver; CPR anvendes, hvor entydig identifikation er lovpligtig
  eller nødvendig (dbl. § 11). Frontend modtager aldrig fuldt CPR fra
  sessionen.
- **Formålsbegrænsning:** Leverandøren behandler alene efter instruks
  (databehandleraftalens bilag C); ingen sekundær anvendelse.
- **Opbevaringsbegrænsning:** MitID-sessioner udløber automatisk efter 15
  minutter; auditlog renses efter konfigureret retention. Sletning af
  sagsdata sker i dag efter instruks som dokumenteret manuel procedure;
  automatiseret retention pr. sagstype er planlagt (issue #91) - se risiko
  R6.
- **Alternativvurdering:** Papir-/blanketbaseret behandling ville indebære
  ringere sikkerhed (ukrypteret transport/opbevaring, ingen auditlog) og
  dårligere borgerretssikkerhed (ingen håndhævet partshøring/frister).

## 4. Risikovurdering (art. 35, stk. 7, litra c)

Skala: sandsynlighed og konsekvens 1-3 (lav/mellem/høj). Restrisiko efter
foranstaltninger. Statuskolonnen er ærlig: "åben" betyder, at kommunen skal
forholde sig til restrisikoen, før forløbet går i produktion.

| # | Risiko for de registrerede | S | K | Foranstaltning | Restrisiko / status |
|---|---|---|---|---|---|
| R1 | CPR lækkes fra hvilende data (databasekompromittering) | 1 | 3 | Feltnær AES-256-kryptering, fail-closed, tenant-nøgler; nøgler uden for databasen (env/key-provider) | Lav. Lukket for alle CPR-elementtyper efter #172 (verificeret ved regressionstest) |
| R2 | Forkert forælder godkender på barnets vegne (myndighedsfejl i konfliktsager) | 1 | 3 | Samtykkegate verificerer forælder-CPR mod MitID-session, fail-closed; begge forældremyndighedsindehavere skal godkende; submit-time re-verifikation | Lav (tidligere defekt, nu verificeret lukket) |
| R3 | Borger opnår afgørelse uden sagsbehandling (manipulation af statusfelter) | 1 | 3 | Server-side guard på afgørelsesfelter; JSON:API read-only; tilstandsmaskine på entitetsniveau | Lav |
| R4 | MitID-sessionskapabilitet lækker via URL (browserhistorik, Referer, proxylogs) og misbruges inden 15-min-TTL | 2 | 2 | Maskeret CPR i svar, auditlogget opslag inkl. miss (probing er synlig), session bundet til browser-session ved udstedelse | **Åben (mellem): endpointet stoler p.t. på bearer alene; engangskode/HTTPOnly-cookie er planlagt (issue #156)** |
| R5 | Volumetrisk misbrug/enumeration mod API og MitID-ruter | 2 | 2 | Flood control på webform-submit; sessionsopslag auditlogges | **Åben (mellem): rate limiting på JSON:API og MitID-ruter udestår (issue #142)** |
| R6 | Data opbevares længere end nødvendigt | 2 | 2 | Dokumenteret manuel sletteprocedure efter instruks; auditlog auto-renses; MitID-TTL | **Åben (mellem): automatiseret retention/sletning pr. sagstype udestår (issue #91); manuel procedure skal aftales i bilag C.4** |
| R7 | Digital Post sendes to gange eller til forkert modtager | 2 | 2 | Fail-closed transporttilstand; afsendelseslog med hashet modtager | **Åben (mellem): idempotensnøgle og modtager-fra-verificeret-session er planlagt (issue #73); indtil da manuel kontrol i kritiske forløb** |
| R8 | Følsomt underretningsindhold ses af uvedkommende internt | 1 | 3 | Rollebaseret adgang, auditlog med formålsangivelse, personlig produktionsadgang | Lav-mellem; kommunens egen rolletildeling er den bærende kontrol |
| R9 | Registerudfald (Serviceplatformen) blokerer sagsgange | 2 | 1 | Fejl rapporteres ærligt i sagsforløbet (skipped/failed) | Åben (lav): circuit breaker planlagt (issue #72); driftsrisiko, ikke rettighedsrisiko |
| R10 | Auditlog indeholder selv persondata og bliver et mål | 1 | 2 | Identifikatorer hashes (SHA-256), IP nødvendig for sporbarhed, 5 års retention med auto-oprydning, adgang rollebegrænset | Lav |

## 5. Planlagte foranstaltninger og tidsplan (art. 35, stk. 7, litra d)

| Åben risiko | Foranstaltning | Reference | Målsætning |
|---|---|---|---|
| R4 | Engangskode eller HTTPOnly-cookie i stedet for kapabilitet i URL | #156 | Før pilot med rigtige borgerdata |
| R5 | Rate limiting på JSON:API + MitID-ruter | #142 | Før pilot |
| R6 | Retention-/sletningsmotor pr. sagstype | #91 | Før eller tidligt i pilot; indtil da manuel procedure |
| R7 | Idempotensnøgle + modtager fra verificeret session | #73 | Før forløb med bebyrdende afgørelser |

Konklusion (leverandørens indstilling til kommunens DPO): Med de fire
planlagte foranstaltninger gennemført er restrisikoen for de registrerede
lav. Uden dem bør pilot afgrænses til forløb uden bebyrdende afgørelser,
eller kommunen skal aktivt acceptere restrisikoen. Forudgående høring af
Datatilsynet (art. 36) vurderes ikke påkrævet, når foranstaltningerne er
gennemført - vurderingen er kommunens.

## 6. Inddragelse

- **DPO:** Kommunens DPO forelægges denne analyse og fører sin udtalelse i
  afsnit 7.
- **De registrerede:** Borgerforløbene testes med brugerpaneler;
  tilgængelighed (WCAG) auditeres i frontend før erklæring.
- **Leverandør:** Fenix Nordic vedligeholder risikotabellen ved hver
  væsentlig ændring og underretter kommunen (databehandleraftalens punkt 9).

## 7. DPO'ens udtalelse og den dataansvarliges godkendelse

[Udfyldes af kommunen.]

| Rolle | Navn | Dato | Beslutning |
|---|---|---|---|
| DPO | | | |
| Systemejer | | | |
| Dataansvarlig ledelse | | | |
