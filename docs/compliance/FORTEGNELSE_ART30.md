# Fortegnelse over behandlingsaktiviteter (artikel 30)

Udkast. To dele: **del 1** er Leverandørens fortegnelse som databehandler
(artikel 30, stk. 2) og er den, kommunens DPO efterspørger i en
leverandørvurdering. **Del 2** er input til kommunens egen fortegnelse som
dataansvarlig (artikel 30, stk. 1) pr. selvbetjeningsforløb, så kommunen ikke
skal starte fra nul.

---

## Del 1 - Databehandlerens fortegnelse (art. 30, stk. 2)

**Databehandler:** Fenix Nordic [selskabsform], CVR [CVR-nr.], [adresse].
Kontakt: [navn, e-mail]. Databeskyttelsesrådgiver: [navn/e-mail eller "ikke
udpegningspligtig, kontaktpunkt: ..."].

**Dataansvarlige, der behandles for:** [Kommune(r) med aktiv aftale -
vedligeholdes som liste pr. kontrakt.]

### Kategorier af behandling, der foretages på vegne af hver dataansvarlig

| # | Behandlingskategori | Beskrivelse |
|---|---|---|
| 1 | Modtagelse og opbevaring af borgerindsendelser | Webformularer (ansøgninger, anmeldelser, underretninger, samtykker) modtages via platformens API og opbevares i platformens database; CPR-felter krypteres feltnært (AES-256) før skrivning |
| 2 | Identitetssikring | MitID-login (OIDC); sessionsdata (navn, maskeret CPR, fødselsdato, adresse, sikringsniveau) opbevares midlertidigt med 15 minutters TTL |
| 3 | Registeropslag | Opslag i CPR (SF1520) og CVR (SF1530) via Serviceplatformen på kommunens tilslutning; svar anvendes i sagsforløbet og opbevares som sagsdata |
| 4 | Automatiserede sagsskridt | ECA-arbejdsgange: validering, forældre-/værgesamtykke, partshøring, fristberegning, afgørelsesbreve |
| 5 | Forsendelse | Digital Post (SF1601) til borgere; transaktionel e-mail-notifikation via underdatabehandler (EU-region) |
| 6 | Journalisering/aflevering | Overdragelse af sag og dokumenter til kommunens ESDH/fagsystem via konnektor |
| 7 | Auditlogning | Logning af al adgang til personoplysninger (bruger, handling, formål, status, IP, hashet identifikator); retention 5 år, automatisk oprydning |
| 8 | Backup og gendannelse | Krypterede backups af produktionsdata, [30 dage rullende] |
| 9 | Sletning | Sletning efter den dataansvarliges instruks; automatiseret retention pr. sagstype er planlagt (issue #91), p.t. dokumenteret manuel procedure |

### Overførsler til tredjelande

Ingen. Al behandling sker i EU/EØS (hosting i EU-datacenter; e-mail i
EU-region ved kontraktkrav).

### Generel beskrivelse af tekniske og organisatoriske
sikkerhedsforanstaltninger (art. 32, stk. 1)

Se DATABEHANDLERAFTALE.md bilag C.2. Hovedpunkter: feltnær AES-256-kryptering
af CPR med fail-closed adfærd og tenant-specifikke nøgler, maskeret
CPR-visning, MitID-identitetssikring (fail-closed), rollebaseret adgang,
server-side beskyttelse af afgørelsesfelter, håndhævede sagstilstandsregler,
fuld auditlog, TLS, personlig og logget produktionsadgang, åben kildekode
(revisionsbar).

---

## Del 2 - Input til kommunens fortegnelse (art. 30, stk. 1) pr. forløb

Kommunen er dataansvarlig for alle borgerforløb på platformen. Tabellerne
nedenfor er udfyldte udkast; kommunen tilpasser formål/hjemmel til egen
praksis og tilføjer sine slettefrister.

### 2.1 Skoleflytning (skoleskift)

| Felt | Indhold |
|---|---|
| Formål | Behandling af anmodning om skoleskift, indhentelse af samtykke fra begge forældremyndighedsindehavere |
| Hjemmel | Folkeskoleloven § 36, stk. 3 (frit skolevalg); databeskyttelsesforordningen art. 6, stk. 1, litra e; CPR: databeskyttelseslovens § 11, stk. 1 |
| Registrerede | Barnet, forældre/forældremyndighedsindehavere |
| Oplysninger | Navne, CPR-numre (krypteret), adresser, nuværende/ønsket skole, forældremyndighedsforhold, MitID-verifikation, samtykker |
| Modtagere | Afgivende og modtagende skole, kommunens skoleforvaltning; Digital Post-kvittering til forældrene |
| Sletning | [Kommunens frist, fx ved afsluttet indskrivning + X måneder] |

### 2.2 Underretning (fagperson og borger)

| Felt | Indhold |
|---|---|
| Formål | Modtagelse og videresendelse af underretninger om bekymring for et barn |
| Hjemmel | Barnets lov §§ 133-135 (underretningspligt); art. 6, stk. 1, litra c/e; art. 9, stk. 2, litra f/g i det omfang følsomme oplysninger indgår |
| Registrerede | Barnet, forældre, underretter (kan være anonym for borgere) |
| Oplysninger | Bekymringsbeskrivelse (kan indeholde helbredsmæssige og sociale oplysninger), navne, evt. CPR (krypteret), relationer |
| Modtagere | Kommunens familieafdeling; frist håndhæves (24 timer for kvittering jf. praksis) |
| Sletning | Overdrages til sagssystem; platformskopi slettes efter [frist] |

### 2.3 Merudgifter til børn

| Felt | Indhold |
|---|---|
| Formål | Ansøgning om dækning af nødvendige merudgifter ved forsørgelse af barn med funktionsnedsættelse |
| Hjemmel | Barnets lov § 86; art. 6, stk. 1, litra e; **art. 9, stk. 2, litra b/g (helbredsoplysninger)**; CPR: dbl. § 11, stk. 1 |
| Registrerede | Barnet, forældre |
| Oplysninger | Helbreds-/funktionsnedsættelsesoplysninger, udgiftsdokumentation, CPR (krypteret), familieforhold, MitID-verifikation |
| Modtagere | Kommunens socialforvaltning; afgørelse via Digital Post |
| Sletning | [Kommunens frist jf. regnskabs- og sagskrav] |

### 2.4 Forældresamtykke/værgesamtykke (guardian consent, parent request)

| Felt | Indhold |
|---|---|
| Formål | Indhentelse af gyldigt samtykke fra begge forældremyndighedsindehavere i sager, der kræver det (dobbelt-godkendelsesforløb) |
| Hjemmel | Følger hovedsagens hjemmel; forældremyndighed efter forældreansvarsloven; art. 6, stk. 1, litra e |
| Registrerede | Barnet, begge forældre |
| Oplysninger | CPR for forældre (krypteret, verificeret mod MitID-session), forældremyndighedsstatus, samtykkebeslutning, tidsstempler |
| Modtagere | Sagsbehandler; kvittering via Digital Post til begge forældre |
| Sletning | Som hovedsagen |
| Bemærkning | Samtykkegate er fail-closed: forkert eller uverificeret CPR afviser godkendelsen |

### 2.5 PPR-henvisning

| Felt | Indhold |
|---|---|
| Formål | Henvisning af barn til Pædagogisk Psykologisk Rådgivning |
| Hjemmel | Folkeskoleloven § 12, stk. 2 (specialundervisning mv.); art. 9, stk. 2, litra g i det omfang helbredsoplysninger indgår |
| Registrerede | Barnet, forældre, henvisende fagperson |
| Oplysninger | Henvisningsgrundlag (kan indeholde helbred/trivsel), CPR (krypteret), samtykker fra forældremyndighedsindehavere |
| Modtagere | PPR, skole |
| Sletning | [Kommunens frist] |

### 2.6 Valg til MED-udvalg (opstilling og afstemning)

| Felt | Indhold |
|---|---|
| Formål | Digital opstilling og afstemning til MED-udvalg |
| Hjemmel | Kommunens MED-aftale; art. 6, stk. 1, litra e/f |
| Registrerede | Medarbejdere |
| Oplysninger | Navn, CPR til entydig vælgeridentifikation (krypteret), stemmeafgivelse (hemmelig; kun afkrydsning af at der er stemt) |
| Modtagere | Valgudvalg (optælling, ikke enkeltstemmer pr. person) |
| Sletning | Efter valgets endelige opgørelse + klagefrist |

### 2.7 Borgerservice-ansøgninger og adresseændring (demo-/standardforløb)

| Felt | Indhold |
|---|---|
| Formål | Diverse selvbetjeningsforløb (flytning, ansøgninger) |
| Hjemmel | CPR-loven §§ 12-13 (anmeldelse af flytning) hhv. det enkelte forløbs sektorlovgivning |
| Registrerede | Borgere |
| Oplysninger | Navn, CPR (krypteret), adresser, kontaktoplysninger |
| Modtagere | Relevant forvaltning |
| Sletning | [Kommunens frist] |

---

*Vedligehold: Når et nyt forløb konfigureres i platformen, tilføjes en række
her, før forløbet går i produktion. Fortegnelsen er et levende dokument og
skal kunne forevises Datatilsynet på anmodning (art. 30, stk. 4).*
