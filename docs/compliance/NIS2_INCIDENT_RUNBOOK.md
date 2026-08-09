# NIS2 incident-response runbook

**AabenForms drift - hændelseshåndtering med NIS2- og GDPR-frister**

Udkast. Operationel drejebog for sikkerhedshændelser i AabenForms-driften,
skrevet så den kan vedlægges kommunens informationssikkerhedsdokumentation.
Kommuner er omfattet af NIS2 som offentlig forvaltning (væsentlige enheder)
efter den danske NIS2-lov (i kraft 1. juli 2025); Leverandøren indgår i
kommunens leverandørkæde og skal kunne levere input til kommunens
anmeldelser inden for fristerne. Kontaktfelter i [kantede parenteser]
udfyldes pr. kommune før drift.

## 1. Definitioner og klassifikation

**Hændelse:** enhver begivenhed, der kompromitterer tilgængelighed,
integritet, fortrolighed eller autenticitet af platformen eller data i den.

**Væsentlig hændelse (NIS2-anmeldelsespligtig):** en hændelse, der (a) har
forårsaget eller kan forårsage alvorlig driftsforstyrrelse eller økonomisk
tab, eller (b) har påvirket eller kan påvirke andre (fysiske eller
juridiske personer) ved at forvolde betydelig materiel eller immateriel
skade.

**Brud på persondatasikkerheden (GDPR art. 4, nr. 12):** hændelse, der fører
til hændelig/ulovlig tilintetgørelse, tab, ændring eller uautoriseret
videregivelse af eller adgang til personoplysninger.

### Alvorlighedsniveauer

| Niveau | Kriterium | Eksempler |
|---|---|---|
| SEV1 | Bekræftet kompromittering af persondata eller nøglemateriale; platform bruges aktivt til angreb | CPR-krypteringsnøgle lækket; database eksfiltreret; aktiv udnyttelse af MitID-flow |
| SEV2 | Sandsynlig kompromittering eller alvorlig funktionsfejl med databeskyttelseskonsekvens | Kapabilitets-id'er misbrugt inden TTL; Digital Post sendt til forkerte modtagere; auditlog manipuleret |
| SEV3 | Driftsforstyrrelse uden tegn på datakompromittering | Længerevarende nedetid, registerudfald (Serviceplatformen), DoS uden databrud |
| SEV4 | Forsøg/scanning uden effekt | Fejlede probes mod sessionendpoint (synlige i auditlog), brute-force stoppet af flood control |

SEV1-SEV2 udløser altid vurdering af **både** NIS2-varsling og GDPR art. 33.
SEV3 kan være NIS2-anmeldelsespligtig (væsentlig driftsforstyrrelse) uden at
være et persondatabrud.

## 2. Frister (de tal, alle skal kunne udenad)

### NIS2 (kommunen som væsentlig enhed; CSIRT: CFCS)

| Frist | Handling |
|---|---|
| **24 timer** | Tidlig varsling til CSIRT'en (CFCS) ved væsentlig hændelse: mistanke om ondsindet handling og evt. grænseoverskridende påvirkning angives |
| **72 timer** | Hændelsesanmeldelse: opdateret vurdering, alvorlighed, konsekvenser, kompromitteringsindikatorer |
| Ved anmodning | Statusrapport til CSIRT'en |
| **1 måned** | Endelig rapport: detaljeret beskrivelse, årsag, afhjælpning, evt. grænseoverskridende virkning |

### GDPR

| Frist | Handling |
|---|---|
| **Uden unødig forsinkelse, senest 24 timer** (kontraktkrav, databehandleraftalens punkt 9) | Leverandøren underretter kommunen om brud hos Leverandøren eller underdatabehandler |
| **72 timer** | Kommunen (dataansvarlig) anmelder brud til Datatilsynet (art. 33), medmindre bruddet sandsynligvis ikke indebærer risiko |
| **Uden unødig forsinkelse** | Underretning af de registrerede ved sandsynlig høj risiko (art. 34) - relevant ved CPR-/børnedata |

Fristerne løber parallelt. En SEV1 kl. 14:00 en fredag betyder tidlig
NIS2-varsling senest lørdag kl. 14:00 og Datatilsynet-anmeldelse senest
mandag kl. 14:00. Weekend udskyder intet.

## 3. Roller og kontakter

| Rolle | Hvem | Kontakt |
|---|---|---|
| Incident manager (Leverandør) | [navn] | [tlf./e-mail, 24/7-aftale jf. SLA] |
| Teknisk drift (Leverandør) | [navn] | [kontakt] |
| Kommunens informationssikkerhedskoordinator | [navn] | [kontakt] |
| Kommunens DPO | [navn] | [kontakt] |
| CSIRT (CFCS) | Center for Cybersikkerhed | Anmeldelse via virk.dk / CFCS' anmeldelsesportal |
| Datatilsynet | - | Anmeldelse via virk.dk ("Anmeld brud på persondatasikkerheden") |
| Politiet (ved strafbart forhold) | NSK/NC3 | 114 / anmeldelse |

Ansvarsfordeling: **Leverandøren** opdager, inddæmmer, dokumenterer og
leverer teknisk grundlag. **Kommunen** ejer anmeldelserne til CFCS og
Datatilsynet (dataansvarlig/omfattet enhed) - Leverandøren anmelder aldrig
selv på kommunens vegne, men skal levere udkast til anmeldelsestekst inden
for fristerne ovenfor.

## 4. Drejebog pr. fase

### Fase 0 - Detektion (mål: minutter)

Kilder: auditlog-anomalier (fx mange session-miss = probing), driftsalarmer,
CFCS-varsler, borgerhenvendelse, ansvarlig disclosure (SUPPORT.md).
Første handling: opret hændelseslog med UTC-tidsstempler; alt efterfølgende
noteres dér. Udpeg incident manager.

### Fase 1 - Triage og klassifikation (mål: < 1 time)

1. Klassificér SEV1-SEV4 (tabel ovenfor). Ved tvivl: vælg det højere niveau.
2. Afgør: er persondata berørt (art. 33-spor)? Er driften væsentligt
   forstyrret (NIS2-spor)? Begge kan være ja.
3. SEV1/SEV2: ring (ikke mail) til kommunens
   informationssikkerhedskoordinator og DPO. Start 24-timers-uret for
   kontraktuel underretning: send den indledende skriftlige underretning
   **så snart de kendte fakta er nedskrevet** - vent aldrig på fuld
   afklaring.

### Fase 2 - Inddæmning (parallelt med fase 1)

Standardgreb i prioriteret rækkefølge, afhængigt af scenarie:

- Kompromitteret nøgle/adgang: rotér CPR-krypteringsnøgler
  (tenant-specifikke nøgler begrænser blastradius til én kommune), rotér
  API-/OIDC-hemmeligheder, invalider alle MitID-sessioner (15-min-TTL gør
  dette hurtigt selvudtømmende).
- Aktivt misbrug af endpoint: blokér på reverse proxy (IP/mønster); sæt om
  nødvendigt platformen i vedligeholdstilstand frem for at lade
  eksfiltrering fortsætte.
- Forkert udsendt Digital Post: stop køen, dokumentér berørte afsendelser
  via afsendelsesloggen (hashet modtager gør omfangsbestemmelse mulig uden
  ny eksponering).
- Bevar bevismateriale: databasesnapshot og logeksport FØR oprydning;
  auditloggens integritet er central for både CFCS- og
  Datatilsynet-rapporten.

### Fase 3 - Anmeldelse (kommunens spor, Leverandøren leverer udkast)

Leverandøren leverer inden for fristerne udkast indeholdende:

1. Hændelsens karakter og tidslinje (fra hændelsesloggen).
2. Berørte kategorier og antal registrerede/datasæt (fra auditlog og
   afsendelseslog; CPR-omfang opgøres uden at eksponere CPR - hashede
   identifikatorer tælles).
3. Sandsynlige konsekvenser for de registrerede.
4. Trufne og planlagte foranstaltninger.
5. NIS2-specifikt: formodet ondsindet/ikke-ondsindet, evt.
   grænseoverskridende virkning, kompromitteringsindikatorer.

### Fase 4 - Genopretning

Gendan fra verificeret backup om nødvendigt; ved nøglerotation
genkrypteres berørte CPR-felter; verificér med regressionstests
(CprEncryptionPresaveTest m.fl.) før platformen åbnes igen. Kommunen
godkender genåbning af borgerforløb.

### Fase 5 - Efterspil (senest 2 uger efter lukning; NIS2-slutrapport senest 1 måned)

- Post-mortem uden skyldsplacering; tidslinje, rodårsag, hvad der
  detekterede/burde have detekteret.
- Opdater `PROMISES-VS-VERIFIED.md` og DPIA-risikotabellen, hvis hændelsen
  ændrer den verificerede tilstand.
- Opret GitHub-issues for alle afledte forbedringer; sikkerhedsrettelser
  markeres [SECURITY] i changelog (jf. issue #149).
- Levér udkast til NIS2-slutrapport til kommunen.

## 5. Øvelser og vedligehold

- **Tabletop-øvelse** af scenarie SEV1 (nøglelæk) og SEV2 (forkert Digital
  Post) sammen med kommunen: første gang før pilotstart, derefter årligt.
- Runbook revideres ved hver ændring i kontaktkæde, underdatabehandlere
  eller lovgivning, og mindst årligt.
- Kendte åbne svagheder med hændelsesrelevans føres i DPIA'ens risikotabel
  (p.t. #156 kapabilitet i URL, #142 rate limiting, #143 certifikatudløb) -
  vagthavende skal kende dem, fordi de er de mest sandsynlige
  SEV2-scenarier.
