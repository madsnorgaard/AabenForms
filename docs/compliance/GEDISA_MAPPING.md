# GEDISA-kortlægning: ÅbenForms mod KL's principper for god og effektiv digital selvbetjening

Status pr. 8. november 2026, læst op mod KL Blanketter, Blanketmodernisering,
"Principper for god og effektiv digital selvbetjening" (GEDISA-projektet,
senest opdateret 6. november 2026). Dokumentet er en ærlig opgørelse i samme
ånd som `../PROMISES-VS-VERIFIED.md`: hvor noget mangler, står det her med
henvisning til kode og til det issue, der lukker det.

KL-materialet har tre dele: de 10 faste komponenter i en maskinlæsbar
blanket, 10 principper for god selvbetjening og 9 sproglige anbefalinger.
Nedenfor er alle 29 punkter vurderet for de to borgerflader, platformen har i
dag:

- **Den generiske formularvisning** (`pages/forms/[slug].vue` ->
  `components/WebformRenderer.vue` i frontend) viser en webform fra
  `GET /api/webform/{id}` som én flad side. Det er den flade, en kommune får
  for alt, der ikke er håndbygget.
- **Den guidede demo** (`components/demo/DemoScenarioWizard.vue` +
  `composables/useDemoScenario.ts` + `composables/scenarios/*.ts`) har fem
  faste trin, registeropslag med kilde og hjemmel, DKFDS-trinindikator og
  kvitteringslinjer. Den er hårdkodet pr. scenarie og styres ikke af
  webformen.

Det meste af GEDISA-værdien ligger i dag i demoen. Målet med arbejdet
nedenfor er at flytte den ind i den generiske visning, så hver ny blanket får
den gratis.

Opgørelse: 2 punkter opfyldt, 23 delvist, 3 mangler (B3 screening, B8 kladde,
B9 opsummering). Ingen punkter er irrelevante for platformen.

## De 10 komponenter

| Nr | Komponent | Status | Hvad findes | Hvad mangler |
|---|---|---|---|---|
| A1 | Modtager (kommunen) | Delvist | Demo-header viser "Demo-kommune"; breve sender med CVR som afsender (`SendDigitalPostAction.php`, `Memo/MemoBuilder.php`) | Ingen modtagerblok (kommune, forvaltning, adresse, telefon, e-mail) i formular, opsummering eller brev; tenant-navn falder tilbage til "ÅbenForms" |
| A2 | Sådan gør du | Delvist | Demo har overskrift, indledning og trin; hub-kort viser "Hentes automatisk / Du skriver selv" | Trinnene beskriver systemets forløb (Login, Hentede, Sagsbehandling, Digital Post), ikke borgerens opgaver; ingen "hav klar"-liste; generisk visning har ingen indledning |
| A3 | Oplysninger om ansøger | Opfyldt | `applicant_cpr` i alle danske webforms; navn og CPR fra MitID-sessionen (`PrefillController.php`) | Kontaktoplysninger kun i sygedagpenge; building_permit har engelske labels |
| A4 | Oplysninger kommunen skal bruge | Opfyldt | Sagsspecifikke felter pr. webform; "det spurgte vi ikke om"-listen fra prefill | Ingen filupload i nogen webform, selv om demoteksten lover upload af tegninger |
| A5 | Samtykke | Delvist | Hjemmel pr. opslag vises i demoens kildetabel (`LegalBasis.php`); et enkelt engelsk bekræftelsesfelt i address_change | Intet genbrugeligt samtykkeelement (formål, hvad, tilbagetrækning), ingen oplysningspligt (art. 13) ved start |
| A6 | Partsrepræsentation | Delvist | Digital fuldmagt via MitID-claim i klage (`klage.yml`: on_behalf_of, actor_cpr, representation_basis); fail-closed hvis fuldmagt mangler | Kun klage; ingen synlig "for dig selv eller på vegne af en anden"-sektion, ingen skriftlig fuldmagt |
| A7 | Underskrift | Delvist | Identitet bindes server-side til MitID-sessionen ved indsendelse (`WebformApiController.php`); medunderskrift for forælder 2 | Intet erklærings- eller underskriftstrin, ingen tekst om retsvirkning, underskriver og tidspunkt vises ikke på kvitteringen |
| A8 | Henvisning til lovgivning | Delvist | `LegalBasis::MAP` pr. webform; lov i titel og i breve (merudgifter) | Generisk visning viser aldrig hjemmel; forkortelser er inkonsekvente ("SEL §41", "servicelovens § 41", "Serviceloven §41"); intet link til retsinformation.dk |
| A9 | Vejledning til borger | Delvist | `#description` vises som hjælpetekst under feltet; god tekst i sygedagpenge | Ingen vejledning på formularniveau (kontakt, åbningstid), ingen `#help`/`#more`, intet link til borger.dk |
| A10 | Kvitteringsbrev | Delvist | Kvitteringsbreve på én sætning i klage og building_permit; kvitteringslinjer på skærmen i demoen | Ingen standardkvittering (dato, sagsnummer, det du sendte, hvad sker nu, forventet svartid, kontakt, afsender) og ikke sendt i alle flows (merudgifter, sygedagpenge, friplads, skoleskift sender kun afgørelsen) |

## De 10 principper

| Nr | Princip | Status | Hvad findes | Hvad mangler |
|---|---|---|---|---|
| B1 | Spørgsmål grupperet i borgerens rækkefølge | Delvist | Demoens fem faste trin | Grupperingen følger datakilde og pipeline, ikke "Hvad handler det om / Hvem ansøger / Hvad ansøges om / Hvad skal I bruge / Opsummering og underskrift"; ingen sektioner eller wizard-sider i nogen webform |
| B2 | Indledning med overblik | Delvist | Demo har overskrift og indledning pr. scenarie | Ingen "det skal du have klar"; demoens indledning blander demo-metatekst ind; generisk visning har ingen indledning |
| B3 | Adgangsgivende spørgsmål tidligt | Mangler | Klage deaktiverer valg, der ikke kan påklages; sygedagpenge viser om beskæftigelseskravet er opfyldt, men lader borgeren indsende alligevel | Ingen 1-3 screeningsspørgsmål på første trin med besked med det samme |
| B4 | Tydelige trin, tilbage/næste | Delvist | DKFDS trinindikator og Fortsæt/Tilbage i demoen (`FdsStepIndicator.vue`) | Generisk visning er én side; i demoen findes Tilbage kun på trin 3, og trin 4-5 kan ikke navigeres |
| B5 | Forudfyld kendte oplysninger | Opfyldt i demo, delvist generisk | Prefill-API med kilde og hjemmel; kildetabel med "Ret, hvis det ikke passer" | Generisk visning kalder aldrig `/api/prefill` og forudfylder kun CPR; ingen eksplicit bekræftelse af at oplysningerne er rigtige |
| B6 | Vis kun relevante felter | Delvist | `#states` i school_transfer og underretning; skjulte elementer udelades | `WebformRenderer.vue` evaluerer ikke `#states`, så betingede felter vises altid |
| B7 | Vejled med det samme | Delvist | Inline fejl og fejlopsummering (`FdsErrorSummary`) | Validering sker først ved indsendelse; intet svar udløser vejledning undervejs |
| B8 | Gem kladde og vend tilbage | Mangler | `draft: none` i alle 27 webforms; kun sessionStorage til MitID-rundturen; tilgængelighedserklæringen oplyser selv manglen | Hele punktet; herunder Mit Overblik på borger.dk |
| B9 | Opsummering før underskrift med Redigér | Mangler | Trin 3 viser hentede værdier, men indsender direkte; `preview: 0` overalt | Ingen gennemgangsside og ingen Redigér-links til det enkelte felt |
| B10 | Tydeligt næste skridt til sidst | Delvist | Demoens trin 5 med kvitteringslinjer (sagsnummer, status, klagefrist) og brevet i indbakken | Teksten er leverandørvendt ("uden et manuelt skridt på serveren", mødebooking-CTA); ingen "det skal du selv gøre", ingen forventet sagsbehandlingstid, ingen udskrift |

## De 9 sproglige anbefalinger

| Nr | Anbefaling | Status | Eksempler |
|---|---|---|---|
| C1 | Præcise overskrifter | Delvist | Godt: "Hvad kan du ikke på grund af din sygdom?". Svagt: "Det kun du ved", "Det spurgte vi ikke om", "Workflow kører" |
| C2 | Enkle sætninger | Delvist | Lange sammensatte sætninger i demoens indledninger og statusafsnit |
| C3 | Personligt og aktivt | Delvist | "Udfyldes automatisk fra MitID. Krypteres ved modtagelse.", "Der er truffet afgørelse i sagen", "Din indsendelse er modtaget" |
| C4 | Tidssvarende ord | Delvist | "venligst" i otte strenge, "hurtigst muligt", "Succes" som overskrift |
| C5 | Udeladelse af fremmedord | Delvist | "Autentificer", "MitID-claims", "transmitteres", "ECA-engine", "Workflow", "onboarding" i borgerens kolonne |
| C6 | Forklar svære ord | Delvist | klagefrist, medhold/afslag, partshøring, hjemmel, NSIS-niveau, beskæftigelseskrav bruges uden forklaring; 240-timers-reglen forklares godt i sygedagpengebrevet |
| C7 | Kun relevant information | Delvist | Leverandør-CTA på kvitteringstrinnet; "Demo:"-afsnit inde i breve; cookiebanner og cookieside modsiger hinanden; hjælpetekst lover e-mail-opdateringer, der ikke sendes |
| C8 | Korrekt retskrivning | Delvist | "Gennemgådetaljerne", "forældre myndighed", "Dette felttype", "DDMMYY"; ae/oe i stedet for æ/ø i afvisningsbeskeder i flere ECA-flows |
| C9 | Entydige lovhenvisninger | Delvist | Samme lov skrives på fire måder; "DBF art. 6(1)(e)" er ikke dansk citeringspraksis; RSL § 66 og § 67 blandes |

KL's egne eksempler på reglerne, som teksterne skal måles mod:

| Nr | Skriv | Ikke | Regel |
|---|---|---|---|
| C1 | Dagpleje og daginstitutioner | Regler og vejledning for, når dit barn skal starte ... | Overskriften dækker indholdet med færrest mulige ord og viser målgruppen ved behov |
| C2 | Din bopæl | Bopælsoplysninger | Én ting ad gangen, ingen indskud, punktopstilling ved tre eller flere oplysninger |
| C3 | For at vi kan vurdere ... | Til brug for vurdering af ... | Skriv direkte til borgeren, gør aktøren tydelig, undgå passiv og ord på -ing og -else |
| C4 | Deler du lejlighed med en anden? | Bor du sammen med en roomie? | Almindeligt dansk inden for Retskrivningsordbogens ordforråd |
| C5 | I forbindelse med | Ifm. | Ingen fremmedord eller forkortelser, der ikke er juridisk eller fagligt nødvendige |
| C6 | EU/EØS-borger + forklaring | Uforklaret fagterm alene | Bevar nødvendige termer, men forklar dem dér, hvor borgeren har brug for det |
| C7 | "Vi" i feltdialogen: kommunen spørger, borgeren svarer | Baggrund, der ikke hjælper borgeren med at udfylde eller kende sine rettigheder | Kun det, borgeren behøver for at forstå, udfylde og kende den videre proces |
| C8 | "Kommunen" i vejledningen; navngiv myndigheden, især når flere omtales | Stavning uden for Retskrivningsordbogen | Retskrivningsordbogen for stavning, bøjning, orddeling og grammatisk komma |
| C9 | Entydig populærtitel, retskilden som den gælder ved offentliggørelsen | Forkortelser og blandede former | Præcis henvisning med tydelig afsender |

I ÅbenForms betyder det konkret: "vi" er kommunen i felttekster og hjælpetekster
("Vi henter dit CPR-nummer fra dit MitID-login"), og "kommunen" navngives med
tenantens navn i vejledning og breve, ikke "ÅbenForms".

## Vejen til "opfyldt": ændringer i prioriteret rækkefølge

Rækkefølgen er efter værdi for en kommunal køber. De tre første giver en
blanket, der kan genkendes som en KL-blanket; nummer 4 er den mest
efterspurgte, men også den største.

| # | Ændring | Størrelse | Lukker |
|---|---|---|---|
| 1 | Generisk wizard-visning: `webform_wizard_page` vises som DKFDS-trin med trinindikator, Tilbage/Næste og validering pr. trin; de danske webforms struktureres i GEDISA-siderne (Om ansøgningen / Hvem søger / Hvad søger du om / Det skal vi bruge / Opsummering og underskrift) | Stor | B1, B4, del af A2 |
| 2 | Trinnet "Opsummering og underskrift": alle svar som liste med Redigér pr. linje, erklæringsfelt der kræves server-side, underskriver og tidspunkt på kvitteringen | Mellem | B9, A7 |
| 3 | Standardkvittering på skærm og som brev: dato, sagsnummer, det du sendte, hvad sker nu, forventet svartid, kontakt, udskrift; kvitteringsbrev i alle borgerflows | Mellem | B10, A10, del af A1 |
| 4 | Kladde bundet til MitID-sessionen: `GET/PUT/DELETE /api/webform/{id}/draft`, "Gem og fortsæt senere", genoptag-banner; Mit Overblik som opfølgning | Stor | B8 |
| 5 | Evaluering af `#states` i visningen (visible/invisible/required) | Lille-mellem | B6 |
| 6 | Screeningsside først: 1-3 valg og en stop-besked styret af `#states`, i merudgifter, sygedagpenge, skoleskift og klage | Mellem | B3, del af B7 |
| 7 | Indledning, vejledning og modtagerblok: `intro`-element pr. webform, kommunens kontaktoplysninger pr. tenant i header, footer og som afsendernavn i Digital Post | Mellem | A1, A2, A9, B2 |
| 8 | Samtykke og partsrepræsentation: genbrugeligt samtykkeelement, synlig "for dig selv eller på vegne af en anden"-sektion generaliseret fra klage, oplysningspligt på indledningssiden | Mellem | A5, A6 |
| 9 | Filupload i visning og indsendelses-API | Mellem | A4 |
| 10 | Ensartede lovhenvisninger: fuld korttitel, "§ 41, stk. 1", link til retsinformation.dk, "Lovgrundlag" vist for borgeren | Lille | A8, C9 |
| 11 | Sproggennemgang: "venligst", fremmedord og stavefejl i `da.json`; danske labels i building_permit; æ/ø/å i ECA-afvisninger; DDMMYY -> DDMMÅÅ; cookiebanner rettes | Lille | C1-C8 |
| 12 | Svarstyret vejledning: besked under feltet når et svar kræver det, validering ved blur | Lille-mellem | B7 |

## Sproglige rettelser klar til at tage med

Fra `i18n/locales/da.json` i frontend (linjenumre pr. 8. november 2026):

| Nøgle | Nu | Forslag |
|---|---|---|
| `demo.byggetilladelse.form.validation` | Udfyld venligst alle felter inden indsendelse | Udfyld alle felter, før du sender ansøgningen |
| `form.loadError` | Kunne ikke indlæse formularen. Prøv venligst igen. | Vi kunne ikke hente formularen. Prøv igen. |
| `form.validationErrors` | Ret venligst følgende fejl: | Ret disse fejl, før du sender: |
| `form.unsupportedFieldType` | Dette felttype understøttes ikke endnu | Vi kan ikke vise dette felt endnu. Kontakt kommunen, hvis du ikke kan udfylde ansøgningen. |
| `errors.invalidCPR` | Ugyldigt CPR-nummer (format: DDMMYY-XXXX) | Skriv dit CPR-nummer med 10 cifre, fx 010190-1234 |
| `demo.step.approvalDesc` | En samtykkeanmodning om fælles forældre myndighed kræver din godkendelse. Gennemgådetaljerne og autentificer med MitID for at bekræfte. | Du skal godkende en anmodning, der kræver samtykke fra begge forældremyndighedsindehavere. Læs oplysningerne, og bekræft med MitID. |
| `payment.successMessage` | Din betaling er blevet behandlet succesfuldt. | Vi har modtaget din betaling. |
| `form.successHeading` | Din indsendelse er modtaget | Vi har modtaget din ansøgning |
| `form.mitidRequired` | Denne ansøgning kræver, at du bekræfter din identitet med MitID, før du kan udfylde den. | Log ind med MitID, før du udfylder ansøgningen. Så henter vi de oplysninger, kommunen allerede har om dig. |
| `help.workflow.text` | Her kan du følge status på din ansøgning. Du modtager også opdateringer via e-mail og Digital Post. | Her kan du følge din sag. Du får besked i Digital Post, når der sker noget nyt. |

Samme regel i webform-hjælpeteksterne (`webform.webform.merudgifter.yml`,
`school_transfer.yml`, `sygedagpenge.yml`, feltet applicant_cpr): "Udfyldes
automatisk fra MitID. Krypteres ved modtagelse." bliver til "Vi henter dit
CPR-nummer fra dit MitID-login og krypterer det, når vi modtager det."

## Åbne spørgsmål

- Hvor kommunens kontaktoplysninger skal bo: `aabenforms_tenant` har ingen
  entitet med dem i dag, og frontend læser en `domain`-ressource.
- Om et afsendernavn til Digital Post er konfigureret nogen steder ud over CVR.
- `underretning_flow` har ingen brevskabeloner.
- Der findes ingen borgervendt sagsstatus-side; `pages/cases/inbox.vue` er
  sagsbehandlerens.

## Vedligehold

Opdater tabellerne, når en af ændringerne ovenfor lander, og lad "Delvist"
blive stående, indtil den generiske visning har det, ikke kun demoen.
