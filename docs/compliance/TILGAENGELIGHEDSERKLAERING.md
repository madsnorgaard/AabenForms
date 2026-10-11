# Tilgængelighedserklæring for ÅbenForms

Kilde til erklæringen på https://aabenforms.dk/tilgaengelighed. Siden og denne fil holdes ens; ret begge, når status ændrer sig. En kommune, der tager ÅbenForms i brug, udarbejder sin egen erklæring på [was.digst.dk](https://www.was.digst.dk) for sit eget website og kan henvise til denne for selve selvbetjeningsløsningen.

- **Omfang:** borgerdelen af ÅbenForms på aabenforms.dk: forsiden, kontaktsiden, cookiesiden, demo-oversigten `/demo` og selvbetjeningsforløbene under `/demo/*` og `/forms/*`.
- **Uden for omfang:** sagsbehandler- og administrationsdelen (Drupal-administration, sagsindbakke, Workflow Modeler). Den er ikke testet endnu (backend #245).
- **Lovgrundlag:** lov om tilgængelighed af offentlige organers websteder og mobilapplikationer (webtilgængelighedsloven), som gennemfører EU-direktiv 2016/2102. Standarden er EN 301 549, der henviser til WCAG 2.1 niveau AA.

## Status

**Delvist i overensstemmelse.** Løsningen er bygget på Det Fælles Designsystem (DKFDS 11.3) og testes automatisk. Der er endnu ikke gennemført en fuld manuel test med skærmlæser, og nogle dele mangler (se nedenfor). Derfor erklærer vi ikke fuld overensstemmelse.

## Hvad er testet

Automatisk test med axe-core (WCAG 2.0 og 2.1, niveau A og AA) i Playwright, senest kørt grønt 11. oktober 2026:

- forsiden, kontaktformularen og cookiesiden;
- adressefeltet (combobox), også betjent alene med tastatur;
- en MitID-beskyttet formular i login-tilstand;
- demo-oversigten og hvert demoforløb (byggetilladelse, merudgifter, skoleskift, klage, sygedagpenge) undervejs og ved afslutning.

## Kendte mangler

- Forsiden har stadig enkelte elementer uden for designsystemet (animeret mærkat, glaseffekt og et mørkt kodekort). De omlægges (frontend #19).
- Sagsbehandler- og administrationsdelen er ikke testet (backend #245).
- Der er ikke gennemført en manuel test med skærmlæser (NVDA, VoiceOver) og forstørrelse.
- Testene køres endnu ikke automatisk ved hver ændring (frontend #25). De køres før hver udgivelse.
- Sessionen udløber efter 15 minutter uden mulighed for at forlænge eller gemme en kladde. Det kan ramme brugere, der har brug for mere tid (WCAG 2.2.1, backend #244).

## Sådan har vi vurderet

Egen vurdering, baseret på automatisk test med axe-core og gennemgang af komponenterne mod DKFDS. Erklæringen er udarbejdet 11. oktober 2026 og opdateres ved hver udgivelse, der ændrer borgerdelen.

## Feedback og kontakt

Oplever du problemer med tilgængeligheden, så skriv til kontakt@aabenforms.dk eller brug kontaktsiden. Beskriv gerne, hvad du forsøgte, hvilken side det var, og hvilket hjælpemiddel du brugte. Vi svarer inden for 10 arbejdsdage.

## Klagemulighed

Får du ikke et tilfredsstillende svar, kan du klage til Digitaliseringsstyrelsen, som fører tilsyn med webtilgængelighedsloven: https://www.was.digst.dk.
