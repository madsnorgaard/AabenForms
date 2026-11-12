# ÅbenForms Mock Services - Quick Reference Card

Quick reference for the local DDEV mock services. MitID is a Keycloak mock realm,
Serviceplatformen (CPR/CVR) is a WireMock mock, and Adressevælger is mocked via Prism. None
of these talk to live Danish government endpoints.

---

## Quick Start

```bash
ddev start              # Start everything (includes mocks)
ddev mocks-status       # Check if mocks are running
ddev mocks-logs         # View logs
```

---

## Service URLs

| Service | URL | Admin/UI |
|---------|-----|----------|
| **Keycloak (MitID)** | http://localhost:8080 | http://localhost:8080/admin |
| **WireMock (Serviceplatformen)** | http://localhost:8081 | http://localhost:8081/__admin |
| **Prism (Adressevælger)** | http://localhost:8082 | http://localhost:8082 |
| **Drupal Backend** | https://aabenforms.ddev.site | - |
| **Nuxt Frontend** | http://localhost:3000 | - |

---

## Test Users (Password: test1234)

| Username | Name | CPR | Type |
|----------|------|-----|------|
| `freja.nielsen` | Freja Nielsen | 0101904521 | Citizen (Copenhagen) |
| `mikkel.jensen` | Mikkel Jensen | 1502856234 | Citizen (Common name) |
| `sofie.hansen` | Sofie Hansen | 2506924015 | Citizen (Young parent) |
| `lars.andersen` | Lars Andersen | 0803755210 | Citizen (Aarhus) |
| `emma.pedersen` | Emma Pedersen | 1010005206 | Citizen (Young adult) |
| `karen.christensen` | Karen Christensen | 1205705432 | **Business** (CVR: 12345678) |
| `protected.person` | [BESKYTTET] | 0101804321 | **Protected** (Hidden data) |
| `morten.rasmussen` | Morten Rasmussen | 2209674523 | Citizen (Senior) |
| `ida.mortensen` | Ida Mortensen | 0507985634 | Citizen (Odense) |
| `peter.larsen` | Peter Larsen | 1811826547 | Citizen (Typical male) |

---

## Keycloak Admin Access

- **URL**: http://localhost:8080/admin
- **Username**: `admin`
- **Password**: `admin`
- **Realm**: `danish-gov-test`

---

## Quick Tests

### Test MitID Login
```bash
# Visit test user account page
open http://localhost:8080/realms/danish-gov-test/account
# Login: freja.nielsen / test1234
```

### Test CPR Lookup (SF1520)
```bash
curl -X POST http://localhost:8081/sf1520 \
  -H "Content-Type: text/xml" \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns="http://kombit.dk/xml/schemas/RequestPersonBaseDataExtended/1/"><soapenv:Body><ns:GetPersonBaseDataExtended><ns:CPRNumber>0101904521</ns:CPRNumber></ns:GetPersonBaseDataExtended></soapenv:Body></soapenv:Envelope>'
```

### Test Address Search
```bash
curl "http://localhost:8082/adresser/autocomplete?q=frederiksberg"
```

### Test OIDC Discovery
```bash
curl http://localhost:8080/realms/danish-gov-test/.well-known/openid-configuration
```

---

## Drupal Configuration Snippet

**File**: `web/sites/default/settings.local.php`

```php
// MitID Mock
$config['aabenforms_mitid.settings']['oidc']['issuer'] = 'http://localhost:8080/realms/danish-gov-test';
$config['aabenforms_mitid.settings']['oidc']['client_id'] = 'aabenforms-backend';
$config['aabenforms_mitid.settings']['oidc']['client_secret'] = 'aabenforms-backend-secret-change-in-production';
$config['aabenforms_mitid.settings']['mock_mode'] = TRUE;

// Serviceplatformen Mock (CPR SF1520 / CVR SF1530 via WireMock)
$config['aabenforms_core.settings']['serviceplatformen']['endpoint'] = 'http://localhost:8081';
$config['aabenforms_core.settings']['serviceplatformen']['mock_mode'] = TRUE;

// Adressevælger Mock (Prism). Adressevælger address lookup is a webform element in
// aabenforms_webform, not a standalone module.
$config['aabenforms_webform.settings']['adressevaelger']['api_url'] = 'http://localhost:8082';
```

---

## Nuxt 4 Configuration Snippet

**File**: `nuxt.config.ts`

```typescript
export default defineNuxtConfig({
  auth: {
    strategies: {
      mitid: {
        scheme: 'oauth2',
        endpoints: {
          authorization: 'http://localhost:8080/realms/danish-gov-test/protocol/openid-connect/auth',
          token: 'http://localhost:8080/realms/danish-gov-test/protocol/openid-connect/token',
          userInfo: 'http://localhost:8080/realms/danish-gov-test/protocol/openid-connect/userinfo',
        },
        clientId: 'aabenforms-frontend',
        scope: ['openid', 'profile', 'email'],
        responseType: 'code',
      },
    },
  },
})
```

---

## Troubleshooting

### Keycloak Not Starting?
```bash
ddev mocks-logs keycloak    # Check logs
docker ps                   # Check if container running
lsof -i :8080              # Check port availability
```

### WireMock Not Responding?
```bash
curl http://localhost:8081/__admin/mappings  # List mappings
ddev mocks-logs wiremock                     # Check logs
```

### MitID Login Fails?
1. Check redirect URI in Keycloak client settings
2. Verify `settings.local.php` callback URL
3. Check DDEV site URL: `ddev describe`

---

## OIDC Client Credentials

| Client | Type | Client ID | Secret |
|--------|------|-----------|--------|
| Drupal Backend | Confidential | `aabenforms-backend` | `aabenforms-backend-secret-change-in-production` |
| Nuxt Frontend | Public | `aabenforms-frontend` | (none - public client) |

---

## OIDC Endpoints (MitID Mock)

```
Discovery:      http://localhost:8080/realms/danish-gov-test/.well-known/openid-configuration
Authorization:  http://localhost:8080/realms/danish-gov-test/protocol/openid-connect/auth
Token:          http://localhost:8080/realms/danish-gov-test/protocol/openid-connect/token
Userinfo:       http://localhost:8080/realms/danish-gov-test/protocol/openid-connect/userinfo
JWKs:           http://localhost:8080/realms/danish-gov-test/protocol/openid-connect/certs
Logout:         http://localhost:8080/realms/danish-gov-test/protocol/openid-connect/logout
```

---

## Serviceplatformen Endpoints

```
SF1520 (CPR):         POST http://localhost:8081/sf1520
SF1530 (CVR):         POST http://localhost:8081/sf1530
SF1601 (Digital Post): POST http://localhost:8081/sf1601
```

---

## Adressevælger API Endpoints

```
Autocomplete:  GET http://localhost:8082/adresser/autocomplete?q={query}
Address by ID: GET http://localhost:8082/adresser/{id}
Postal codes:  GET http://localhost:8082/postnumre/{postal_code}
```

---

## Common Scenarios

### Scenario 1: Test Citizen Complaint Workflow
1. Login as `freja.nielsen` (MitID mock)
2. CPR auto-extracted: `0101904521`
3. Address auto-filled from SF1520
4. Submit form
5. Check Digital Post sent (SF1601)

### Scenario 2: Test Business Application
1. Login as `karen.christensen` (MitID Erhverv)
2. CPR: `1205705432`, CVR: `12345678`
3. Company name: "Test ApS"
4. Lookup company data (SF1530)
5. Submit business form

### Scenario 3: Test Protected Person
1. Login as `protected.person`
2. Name/address hidden: `[BESKYTTET]`
3. Form shows CPR only
4. Address lookup disabled
5. Submit with limited data

---

## Useful Drush Commands

```bash
# Test CPR extraction
ddev drush ev "\$e = \Drupal::service('aabenforms_mitid.cpr_extractor'); echo \$e->extractCpr('JWT_TOKEN');"

# Test SF1520 lookup
ddev drush ev "\$c = \Drupal::service('aabenforms_core.serviceplatformen_client'); print_r(\$c->execute('sf1520', 'GetPersonBaseDataExtended', ['CPRNumber' => '0101904521']));"

# Test session storage
ddev drush ev "\$s = \Drupal::service('aabenforms_mitid.session_manager'); \$s->storeSession('test-123', ['cpr' => '0101904521']); echo \$s->getCprFromSession('test-123');"

# Check audit log
ddev drush sql:query "SELECT * FROM aabenforms_audit_log ORDER BY timestamp DESC LIMIT 10;"
```

---

## Pro Tips

1. **Bookmark Keycloak Admin** - You'll use it often
2. **Use realistic test data** - Makes development feel more authentic
3. **Test edge cases** - Protected persons, high assurance levels
4. **Check logs early** - `ddev mocks-logs` catches issues fast
5. **Keep this reference handy** - Save time looking up URLs

---

## Full Documentation

- **Comprehensive Guide**: `docs/DDEV_MOCK_SERVICES_GUIDE.md`
- **International Standards**: `docs/INTERNATIONAL_STANDARDS_AND_TOOLS.md`
- **Danish Infrastructure**: `reports/DANISH_INFRASTRUCTURE_ALIGNMENT.md`

---

## Need Help?

1. Check logs: `ddev mocks-logs [service]`
2. Verify status: `ddev mocks-status`
3. Restart services: `ddev restart`
4. Read full docs: `docs/DDEV_MOCK_SERVICES_GUIDE.md`
5. Open issue: https://github.com/aabenforms/aabenforms/issues

---

Drupal 11.3.10 / PHP 8.4 / ECA 3.1.1. Pre-pilot POC.
