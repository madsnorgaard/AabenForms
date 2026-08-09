# Databehandleraftale

**Standardkontraktsbestemmelser (databeskyttelsesforordningens artikel 28, stk. 3)**

Udkast. Strukturen følger Datatilsynets standardkontraktsbestemmelser for
databehandleraftaler (den danske skabelon godkendt af Det Europæiske
Databeskyttelsesråd). Felter i [kantede parenteser] udfyldes pr. kommune før
underskrift. Dokumentet er en draft til brug i udbuds- og pilotdialog og er
ikke juridisk rådgivning.

---

## Parterne

**Den dataansvarlige:**
[Kommune], CVR [CVR-nr.], [adresse] ("Kommunen")

**Databehandleren:**
Fenix Nordic [selskabsform], CVR [CVR-nr.], [adresse] ("Leverandøren"),
som driftsansvarlig for AabenForms-platformen.

## 1. Præambel

1. Disse bestemmelser fastsætter databehandlerens rettigheder og
   forpligtelser, når databehandleren behandler personoplysninger på vegne af
   den dataansvarlige som led i driften af selvbetjenings- og
   sagsbehandlingsplatformen **AabenForms** (headless Drupal-backend med
   tilhørende borgervendt frontend).
2. Bestemmelserne er udformet med henblik på parternes efterlevelse af
   artikel 28, stk. 3, i databeskyttelsesforordningen (forordning (EU)
   2016/679) samt databeskyttelsesloven, herunder § 11 om behandling af
   CPR-numre.
3. I forbindelse med leveringen af AabenForms behandler databehandleren
   personoplysninger på vegne af den dataansvarlige i overensstemmelse med
   disse bestemmelser.
4. Bestemmelserne har forrang i forhold til eventuelle tilsvarende
   bestemmelser i andre aftaler mellem parterne, herunder hovedaftalen om
   levering af AabenForms.
5. Der hører fire bilag til disse bestemmelser (A-D), og bilagene udgør en
   integreret del af bestemmelserne.

## 2. Den dataansvarliges forpligtelser og rettigheder

1. Den dataansvarlige har overordnet ansvaret for, at behandlingen af
   personoplysninger sker inden for rammerne af databeskyttelsesforordningen
   og databeskyttelsesloven. Kommunen er som offentlig myndighed berettiget
   til at behandle CPR-numre efter databeskyttelseslovens § 11, stk. 1.
2. Den dataansvarlige har derfor både retten og pligten til at træffe
   beslutninger om, til hvilke formål og med hvilke hjælpemidler der må ske
   behandling. Det materielle hjemmelsgrundlag for de enkelte
   selvbetjeningsforløb (fx folkeskoleloven, barnets lov, forvaltningsloven)
   fremgår af bilag A.

## 3. Databehandleren handler efter instruks

1. Databehandleren må kun behandle personoplysninger efter dokumenteret
   instruks fra den dataansvarlige, medmindre det kræves i henhold til
   EU-ret eller national ret. Instruksen fremgår af bilag C.
2. Databehandleren underretter omgående den dataansvarlige, hvis en instruks
   efter databehandlerens mening er i strid med databeskyttelsesreglerne.

## 4. Fortrolighed

1. Databehandleren giver kun adgang til personoplysninger til personer, der
   er underlagt fortrolighedsforpligtelse, og kun i det omfang det er
   nødvendigt (need-to-know). Adgang til produktionsmiljøet er personlig,
   navngiven og logget.
2. Databehandleren kan efter anmodning påvise, at de pågældende personer er
   underlagt fortrolighedsforpligtelse.

## 5. Behandlingssikkerhed

1. Parterne har vurderet sikkerheden ud fra risikoen for de registrerede,
   herunder at platformen behandler CPR-numre, oplysninger om børn og
   forældremyndighed samt i visse forløb helbredsoplysninger (artikel 9).
   De tekniske og organisatoriske foranstaltninger fremgår af bilag C,
   afsnit C.2.
2. Kernen i foranstaltningerne er: feltnær AES-256-kryptering af CPR-numre
   i hvile med fail-closed adfærd (en indsendelse afvises frem for at gemme
   CPR i klartekst), krypteret transport (TLS), rollebaseret adgang, fuld
   auditlogning af adgang til personoplysninger og maskeret visning af CPR.

## 6. Anvendelse af underdatabehandlere

1. Databehandleren må ikke gøre brug af en underdatabehandler uden forudgående
   generel skriftlig godkendelse fra den dataansvarlige.
2. Databehandleren har den dataansvarliges generelle godkendelse til brug af
   de underdatabehandlere, der er anført i bilag B. Databehandleren
   underretter skriftligt om planlagte ændringer med mindst [30] dages
   varsel, og den dataansvarlige kan gøre indsigelse.
3. Databehandleren pålægger underdatabehandleren de samme
   databeskyttelsesforpligtelser som i disse bestemmelser og forbliver fuldt
   ansvarlig over for den dataansvarlige.

## 7. Overførsel til tredjelande eller internationale organisationer

1. Al behandling sker på infrastruktur i EU/EØS (bilag B). Enhver overførsel
   af personoplysninger til tredjelande må kun ske efter dokumenteret
   instruks fra den dataansvarlige og med gyldigt overførselsgrundlag
   (kapitel V).

## 8. Bistand til den dataansvarlige

1. Databehandleren bistår, under hensyntagen til behandlingens karakter, så
   vidt muligt den dataansvarlige med opfyldelse af dennes forpligtelser
   over for de registrerede (artikel 12-22), herunder indsigt, berigtigelse
   og sletning. Platformens auditlog og eksportfunktioner understøtter
   besvarelse af indsigtsanmodninger.
2. Databehandleren bistår ligeledes den dataansvarlige med artikel 32-36,
   herunder anmeldelse af brud (jf. punkt 9) og konsekvensanalyser (se
   DPIA_KONSEKVENSANALYSE.md som arbejdsgrundlag).

## 9. Underretning om brud på persondatasikkerheden

1. Databehandleren underretter den dataansvarlige **uden unødig forsinkelse
   og senest inden for [24] timer** efter at være blevet opmærksom på et brud
   på persondatasikkerheden hos databehandleren eller en underdatabehandler,
   så den dataansvarlige kan overholde sin 72-timers anmeldelsesfrist til
   Datatilsynet (artikel 33).
2. Underretningen følger eskalationsproceduren i NIS2_INCIDENT_RUNBOOK.md og
   indeholder som minimum: bruddets karakter, berørte kategorier og antal
   registrerede, sandsynlige konsekvenser og trufne/foreslåede
   foranstaltninger.

## 10. Sletning og returnering af oplysninger

1. Ved ophør af tjenesterne returnerer databehandleren efter den
   dataansvarliges valg alle personoplysninger i et struktureret, almindeligt
   anvendt format og sletter derefter samtlige kopier, medmindre EU-ret eller
   national ret kræver opbevaring. Sletningen dokumenteres skriftligt.
2. Slettefrister for de enkelte oplysningstyper i drift fremgår af bilag C,
   afsnit C.4. **Ærlig status:** en automatiseret retention-/sletningsmotor
   pr. sagstype er planlagt, men endnu ikke bygget (GitHub issue #91).
   Indtil den er i drift, udføres sletning efter instruks som en manuel,
   dokumenteret driftsprocedure. Auditloggen renses automatisk efter den
   konfigurerede retention (standard 5 år).

## 11. Revision, herunder inspektion

1. Databehandleren stiller alle oplysninger, der er nødvendige for at påvise
   overholdelse af artikel 28, til rådighed for den dataansvarlige og giver
   mulighed for og bidrager til revisioner og inspektioner.
2. Formen aftales i bilag C, afsnit C.6 (fx årlig skriftlig
   overensstemmelseserklæring plus inspektionsadgang med [14] dages varsel).
   Platformens kildekode er åben (GPL-2.0-or-later), hvilket giver den
   dataansvarlige en revisionsadgang, en lukket leverandør ikke kan tilbyde.

## 12. Parternes aftaler om andre forhold

1. Regulering af økonomi, ansvar og misligholdelse ved brud på disse
   bestemmelser fremgår af parternes hovedaftale (SLA-vilkår aftales
   særskilt).

## 13. Ikrafttræden og ophør

1. Bestemmelserne træder i kraft ved begge parters underskrift og gælder, så
   længe databehandleren behandler personoplysninger på vegne af den
   dataansvarlige.

---

## Bilag A - Oplysninger om behandlingen

**A.1 Formål:** Drift af kommunens digitale selvbetjenings- og
arbejdsgangsplatform: modtagelse af ansøgninger og anmeldelser fra borgere,
identitetssikring (MitID), automatiserede sagsskridt (ECA-flows), afsendelse
af Digital Post og aflevering til kommunens fagsystemer/ESDH.

**A.2 Behandlingens karakter:** Indsamling via webformularer, opslag i
autoritative registre (CPR via SF1520, CVR via SF1530 - via
Serviceplatformen, når kommunens tilslutning foreligger), strukturering,
opbevaring (krypteret for CPR), forsendelse (Digital Post SF1601),
journalisering og sletning.

**A.3 Kategorier af registrerede:** Borgere (ansøgere), børn, forældre og
forældremyndighedsindehavere, medarbejdere hos kommunen (sagsbehandlere),
fagpersoner der indgiver underretninger.

**A.4 Kategorier af personoplysninger:**

| Kategori | Eksempler | Klassifikation |
|---|---|---|
| Identifikation | Navn, CPR-nummer, adresse, kontaktoplysninger | Alm. + CPR (dbl. § 11) |
| Identitetssikring | MitID-sessionsdata, sikringsniveau (assurance level) | Alm. |
| Familieforhold | Forældremyndighed, samlevende/bopælsstatus, barn-forælder-relationer | Alm., beskyttelsesværdige, vedrører børn |
| Helbred | Oplysninger om funktionsnedsættelse/merudgifter (fx forløb efter barnets lov § 86), PPR-henvisningsgrundlag | **Artikel 9 (følsomme)** |
| Sociale forhold | Underretningsindhold om bekymring for et barn | Følsomt indhold, skærpet beskyttelse |
| Sagsdata | Ansøgningsindhold, afgørelsesbreve, frister, status | Alm. |
| Tekniske spor | Auditlog (bruger, handling, formål, IP, hashet identifikator), driftslogs | Alm. |

**A.5 Varighed:** Så længe hovedaftalen løber; slettefrister jf. bilag C.4.

**A.6 Materielt hjemmelsgrundlag pr. forløb (eksempler, udfyldes af
kommunen):** skoleflytning (folkeskoleloven § 36, stk. 3), underretning
(barnets lov §§ 133-135), merudgifter til børn (barnets lov § 86),
partshøring og afgørelse (forvaltningsloven §§ 19-25), valg til MED-udvalg
(kommunens MED-aftale).

## Bilag B - Underdatabehandlere

Godkendte underdatabehandlere ved aftalens indgåelse:

| Navn | CVR/reg. | Ydelse | Lokation |
|---|---|---|---|
| [Hostingleverandør, fx Contabo GmbH] | [reg.nr.] | Server-/infrastrukturdrift (produktionsmiljø) | EU (Tyskland) |
| [E-mailleverandør, fx Mailgun EU] | [reg.nr.] | Transaktionel e-mail (notifikationer, ikke Digital Post) | EU-region (kontraktkrav) |

Bemærk: Serviceplatformen/KOMBIT og Digital Post er **ikke**
underdatabehandlere for Leverandøren; kommunens egen tilslutningsaftale
gælder. Ingen underdatabehandlere uden for EU/EØS.

## Bilag C - Instruks

**C.1 Behandlingens genstand/instruks:** Databehandleren behandler alene
oplysningerne til drift af de i bilag A nævnte forløb, som kommunen har
konfigureret og taget i brug. Ingen behandling til egne formål; ingen
anvendelse til udvikling/test uden forudgående anonymisering.

**C.2 Behandlingssikkerhed (tekniske og organisatoriske foranstaltninger):**

- Feltnær AES-256-kryptering af CPR i hvile; fail-closed (indsendelse
  afvises, hvis krypteringsnøglen er utilgængelig). Ved multi-tenant-drift
  anvendes tenant-specifikke nøgler, så en kommunes nøgle ikke kan afkode en
  andens data.
- CPR vises maskeret (DDMMÅÅ-XXXX) i borger- og sessionsflader; fuldt CPR
  eksponeres kun ved dokumenteret tjenstligt behov.
- Fuld auditlog af adgang til og opslag på personoplysninger (bruger,
  handling, formål, status, IP, SHA-256-hashet identifikator) med
  konfigurerbar retention (standard 5 år) og automatisk oprydning.
- Identitetssikring af borgerforløb via MitID (OIDC); fail-closed som
  standard, demo-tilstand kræver eksplicit konfigurationsflag og er
  deaktiveret i produktion.
- Rollebaseret adgang; afgørelsesbærende felter kan ikke sættes af borgeren
  (server-side guard); lovlige sagstilstandsovergange håndhæves på
  entitetsniveau (forvaltningsloven §§ 19-25 understøttet i datamodellen).
- TLS på al transport; adgang til produktion via personlige nøgler.
- Kendte, planlagte forbedringer føres åbent i issue-trackeren, p.t. bl.a.
  udvidet rate limiting (#142) og automatiseret retention (#91). Status
  meddeles kommunen ved væsentlige ændringer.

**C.3 Bistand:** Jf. punkt 8-9; svarfrister for bistand aftales i SLA.

**C.4 Opbevaring/sletning (udfyldes endeligt af kommunen pr. sagsområde):**

| Datatype | Frist (udkast) | Ansvar |
|---|---|---|
| Webform-indsendelser efter aflevering til ESDH/fagsystem | [90 dage] | Databehandler efter instruks |
| MitID-sessionsdata | 15 minutter (TTL, automatisk) | Automatisk |
| Auditlog | 5 år (konfigurerbar) | Automatisk |
| Digital Post-afsendelseslog | [1 år] | Databehandler efter instruks |
| Backups | [30 dage rullende] | Databehandler |

**C.5 Lokation:** Produktionsmiljø: [api.aabenforms.dk eller
kommune-specifik instans], EU-datacenter jf. bilag B.

**C.6 Revision:** Årlig skriftlig erklæring om efterlevelse + inspektion med
[14] dages varsel. Kildekoden er offentligt tilgængelig og kan revideres
frit.

## Bilag D - Parternes regulering af andre forhold

[SLA-henvisning, supportvindue, exitplan inkl. dataeksportformat
(struktureret JSON/CSV pr. forløb), pris for bistand ud over det aftalte.]

---

*Underskrifter:*

| For den dataansvarlige | For databehandleren |
|---|---|
| Dato: | Dato: |
| Navn/titel: | Navn/titel: |
