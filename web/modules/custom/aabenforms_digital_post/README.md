# AabenForms Digital Post

Send SF1601 Digital Post from any modern Drupal 11 site, with minimal dependencies and no heavy ecosystem to adopt.

- Hard Drupal dependencies: `drupal:key`, `aabenforms_core`. No webform and no heavy ecosystem modules required. The webform handler is its own optional submodule.
- One composer library dependency: `itk-dev/serviceplatformen` (bundled MeMo and Fjernprint types included).
- Four test modes: `fake_db` (default, zero-config), `wiremock`, `live_test`, `live`.
- Public API is a single value object and a single service call; no webform coupling in the core module.
- ECA / BPMN integration ships in the `aabenforms_digital_post_eca` submodule (plugin id `aabenforms_digital_post_send`).
- Delivery-status receipts, OS2Web certificate storage, and advanced queueing all live in optional bridge submodules (session 3).

## Install

```sh
composer require itk-dev/serviceplatformen -W
drush pm:enable aabenforms_digital_post
```

## Configure

```sh
# Install default is fake_db. Nothing else required to start.
drush af:dp:status

# Set your sender CVR when ready.
drush config:set aabenforms_digital_post.settings sender_cvr 12345678 -y

# Switch transports by flipping one key.
drush config:set aabenforms_digital_post.settings test_mode wiremock -y
drush config:set aabenforms_digital_post.settings test_mode live_test -y   # session 2+
```

Admin form: `/admin/config/aabenforms/digital-post`.

## Send

```php
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;

$result = \Drupal::service('aabenforms_digital_post.sender')->send(
  new DigitalPost(
    recipient: Recipient::cpr('0101900000'),
    sender: Sender::fromConfig(\Drupal::configFactory()),
    subject: 'Afgørelse',
    body: '<p>Hej</p>',
  )
);
if ($result->isSuccess()) {
  $tx = $result->transactionId;
}
else {
  // $result->reasonCode is one of CERT_INVALID, RECIPIENT_UNKNOWN,
  // RECIPIENT_NOT_REACHABLE, QUOTA, TRANSPORT, VALIDATION, UNKNOWN.
}
```

## Smoke test from the CLI

```sh
drush af:dp:send --to=0101900000 --subject="Test" --body="<p>Hello</p>"
drush af:dp:log:tail
```

## Test modes

| Mode | What it does | Dependencies |
|------|-------------|--------------|
| `fake_db` | Writes the would-be payload to `{aabenforms_digital_post_log}` and returns a synthetic receipt. The "plug-and-play on any Drupal 11" default. | None |
| `wiremock` | POSTs a JSON body to a WireMock endpoint (`wiremock_url` setting). For CI and DDEV dev loops. | WireMock reachable at configured URL |
| `live_test` | Real `itk-dev/serviceplatformen` SF1601 client against Serviceplatformen's exttest endpoint. | Real test certificate |
| `live` | Production Serviceplatformen endpoint. | Real production certificate |

`live_test` and `live` ship in session 2 alongside a real `MemoBuilder` that constructs SF1601-compliant MeMo XML.

## Digital Post fritagelse (exempt citizens) - DEMO

Status: **DEMO**. Nothing here talks to a real government service yet. Issue #242.

About 6 to 7 percent of adults are fritaget (exempt) from Digital Post. A decision (afgørelse) sent only digitally to them would never arrive, so `DigitalPostSender::send()` picks a delivery channel for every CPR recipient before it sends. Flows and the `aabenforms_digital_post_send` action configuration do not change.

1. **Registration lookup** (`DigitalPostRegistrationLookupInterface`, service `aabenforms_digital_post.registration_lookup`) answers `registered`, `exempt` or `unknown`, plus mode `demo` or `live`. Selected by `registration_lookup` in `aabenforms_digital_post.settings` (default `demo`).
   - `DemoRegistrationLookup` (DEMO): a fixed fixture of the fictitious demo personas. Birthe Jensen (2403482210) is exempt; Freja, Mikkel, Sofie and Lars are registered; every other CPR is `unknown`. It answers `unknown` for everyone when `test_mode` is `live_test`/`live`, so a demo fixture never decides a real citizen's letter.
   - `LiveRegistrationLookup`: a stub that answers `unknown` for every CPR. TODO #76: the NgDP / SF1601 kontaktregister lookup.
   - CVR recipients are always `registered` (businesses cannot be exempted). A lookup error counts as `unknown`.
2. **Channel** (`DeliveryChannel::select()`): registered -> `digital` (MeMo/SF1601 as before); exempt -> `physical`; unknown -> `digital_and_physical`. An explicit `Fysisk Post` type is physical; NemSMS and CVR stay digital.
3. **Physical transport** (`PhysicalPostTransport`, DEMO): a second `Sf1601ClientInterface` transport. It records a print job in `{aabenforms_digital_post_log}` with `channel = physical`, `mode = physical_demo`, `status = queued_print`. Nothing is printed or posted. In `live_test`/`live` it refuses with reason `PRINT_UNAVAILABLE` (fail closed: no fake "sent" for a real case).
4. **Unknown status, the fail-safe**: the letter goes out digitally AND as a physical letter (the physical leg has its own transaction id `<tx>-print`), the Result carries `manual_followup = true`, and a `digital_post_manual_followup` audit event is written. The physical leg is decisive: the send is a success only if the print job was queued, because that is the only channel that reaches the citizen whatever their status. If it fails, the send fails, the flow keeps the case open and the klagefrist does not start.
5. **Result and tokens**: `Result::$deliveryChannel`, `$registrationStatus`, `$manualFollowUp`. The ECA action adds `delivery_channel`, `registration_status` and `manual_followup` to its result token and sets a `[<result_token>_delivery_channel]` companion. `[<result_token>_status]` is still `success` for a queued (DEMO) print job, so existing `gate_letter_ok` gates keep working.
6. **Case and klagefrist**: the action records the channel on the case opened from the same submission (`aabenforms_case.delivery_channel`, an audited revision). `aabenforms_case_set_klagefrist` starts the clock from the assumed receipt date: digital = send date (unchanged); physical or both = send date + `physical_post_delivery_days` (`aabenforms_case.settings`, default 5 calendar days). The 5 days is a conservative assumption, not law: the kommune's jurist must confirm it.
7. **Session inbox**: each row of `/api/digital-post/recent` carries `channel` (`digital`|`physical`) so the demo frontend can show "sendt som fysisk brev".

To go live: implement the kontaktregister lookup (#76) and a real print transport (NgDP print channel, or send `Automatisk Valg` and let NgDP route), then remove the live-mode refusal in `PhysicalPostTransport`.

## What ships in session 1

- `DigitalPost`, `Attachment`, `Recipient`, `Sender`, `Result` value objects with format validation in constructors.
- `DigitalPostSender` service (`aabenforms_digital_post.sender`).
- `Sf1601ClientInterface` transport contract.
- `FakeSendDatabaseLogger` transport (default).
- `WireMockSoapClient` transport.
- `CertificateLocatorInterface` + `FileCertificateLocator` + factory.
- `AuditEmitterInterface` + `CoreAuditEmitter` (wraps `aabenforms_core.audit_logger`).
- `TransactionIdGenerator` (UUID v7).
- `SettingsForm` with inline validation at save time.
- Drush commands: `af:dp:send`, `af:dp:log:tail`, `af:dp:status`.
- `{aabenforms_digital_post_log}` table.

## What does NOT ship in session 1

- Real MeMo XML via `itk-dev/serviceplatformen`. Both transports currently carry either a JSON body (wiremock) or a serialized DTO in the log table (fake_db). Session 2 adds the real `MemoBuilder` and wires the live and live_test transports.
- Delivery status (Beskedfordeler callback). Separate submodule `aabenforms_digital_post_beskedfordeler`, session 3.
- A real fjernprint / NgDP print integration. The physical-post channel for citizens fritaget for Digital Post exists, but only as a DEMO transport - see "Digital Post fritagelse" below.
- `os2web_key`, `os2web_audit`, `os2web_datalookup`, `advancedqueue` bridges. All optional, session 3.
- Webform handler. Lives in `aabenforms_digital_post_webform` submodule, session 3.

## Submodules

- **`aabenforms_digital_post_eca`** (Session 2A, SHIPPED). ECA action plugin `aabenforms_digital_post_send` for use in BPMN templates and ECA flows. See `modules/aabenforms_digital_post_eca/README.md`.
- **`aabenforms_digital_post_webform`** (Session 3, planned). Webform handler that maps a `WebformSubmission` to a `DigitalPost` DTO without coupling the core module to webform.
- **`aabenforms_digital_post_beskedfordeler`** (Session 3, planned). Delivery-status receipts via Beskedfordeler. Only enable on sites that need delivery receipts; the core send path does not require it.
- **`aabenforms_digital_post_os2web_key`** (Session 3, planned). Certificate-locator bridge for sites already using `os2web_key`.

## Files

```
aabenforms_digital_post/
├── aabenforms_digital_post.info.yml
├── aabenforms_digital_post.services.yml
├── aabenforms_digital_post.routing.yml
├── aabenforms_digital_post.permissions.yml
├── aabenforms_digital_post.links.menu.yml
├── aabenforms_digital_post.install
├── drush.services.yml
├── config/
│   ├── install/aabenforms_digital_post.settings.yml
│   └── schema/aabenforms_digital_post.schema.yml
└── src/
    ├── Audit/                    AuditEmitter interface + Core/Null impls
    ├── Certificate/              Certificate DTO + Locator interface + File impl + Factory
    ├── DigitalPost/              DigitalPost, Attachment, Recipient, Sender, Result DTOs
    ├── Drush/                    DigitalPostCommands
    ├── Event/                    (placeholder; events land in session 2)
    ├── Exception/                DigitalPostException + subtypes
    ├── Form/                     SettingsForm
    ├── Service/                  DigitalPostSender, Sf1601Client interface + factory, TransactionIdGenerator
    └── TestMode/                 FakeSendDatabaseLogger, WireMockSoapClient
```

## License

GPL-2.0 (aligned with Drupal and OS2 ecosystem). The core module has no AabenForms-specific assumptions in runtime code; a project wanting to rebrand can fork and rename. Report issues at https://github.com/madsnorgaard/aabenforms/issues.
