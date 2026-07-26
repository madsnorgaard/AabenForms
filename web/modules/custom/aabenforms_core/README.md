# ÅbenForms Core Module

**Foundation module for Danish government service integrations**

## Overview

ÅbenForms Core provides essential services for all other ÅbenForms modules:

- **ServiceplatformenClient**: SOAP client for Danish government services (SF1520 CPR, SF1530 CVR, SF1601 Digital Post)
- **EncryptionService**: Field-level AES-256 encryption for sensitive personal data (CPR numbers)
- **AuditLogger**: GDPR-compliant audit logging for all sensitive data access
- **TenantResolver**: Multi-tenant domain detection and configuration

## Dependencies

### Required Drupal Modules
- `key` (^1.22) - Key management
- `encrypt` (^3.2) - Encryption framework
- `real_aes` (^2.6) - AES encryption provider

### Optional Modules
- `domain` (^2.0) - Multi-tenancy support

## Installation

```bash
# Install dependencies
ddev composer require drupal/key drupal/encrypt drupal/real_aes

# Enable module
ddev drush pm:enable aabenforms_core -y

# Clear cache
ddev drush cr
```

## Configuration

### 1. Encryption Setup

Create encryption key and profile:

```bash
# Generate AES-256 encryption key
ddev drush key:generate aabenforms_aes256 encryption --key-type=encryption --key-provider=config

# Configure encryption profile
# Navigate to: Administration » Configuration » System » Encryption profiles
# Create profile:
#   - ID: aabenforms_aes256
#   - Encryption method: Real AES (AES-256-CBC)
#   - Key: aabenforms_aes256
```

### 2. Serviceplatformen Configuration

Configure Danish government service endpoints:

**Administration » Configuration » ÅbenForms » Core Settings**

```yaml
serviceplatformen:
  urls:
    SF1520: 'https://prod.serviceplatformen.dk/service/CPR/CPRBasicInformation/1'
    SF1530: 'https://prod.serviceplatformen.dk/service/CVR/CVROnline/1'
    SF1601: 'https://prod.serviceplatformen.dk/service/DigitalPost/1'
  certificates:
    cert_path: '/path/to/serviceplatformen.crt'
    key_path: '/path/to/serviceplatformen.key'
```

**For development/testing**, use test endpoints:
```yaml
serviceplatformen:
  urls:
    SF1520: 'https://exttest.serviceplatformen.dk/service/CPR/CPRBasicInformation/1'
    # ... etc
```

### 3. Multi-Tenancy (Optional)

A tenant is a Domain plus its own CPR encryption key and profile, so cases and
CPR are isolated per tenant. A tenant does not have to be a whole kommune: in a
magistratsstyre like Aarhus, give each magistrat its own subdomain and provision
it as its own tenant. Stand one up in a single command:

```bash
ddev drush pm:enable aabenforms_tenant -y

# Provision a magistrat as a tenant (Domain + per-tenant CPR key + profile).
ddev drush aabenforms:tenant:provision aarhus_mtm "Aarhus - Teknik og Miljoe" \
  --hostname=mtm.aarhus.aabenforms.ddev.site

# Preview without changing anything.
ddev drush aabenforms:tenant:provision aarhus_mtm "Aarhus - Teknik og Miljoe" --dry-run
```

The per-tenant CPR key uses the `env` provider, so provisioning creates only
config; the command reports the environment variable to set (base64 of 32
random bytes) before CPR can be stored for the tenant, e.g.
`AABENFORMS_CPR_KEY_AARHUS_MTM`. Set it in the host environment and restart.
Re-running the command is safe (idempotent).

Optional tenant-specific integration configuration is stored at:
- `aabenforms_core.tenant.aarhus_mtm`
- `aabenforms_core.tenant.aarhus_mbu`

#### Per-user tenant binding

By default isolation follows the host: a caseworker sees whichever tenant's data
belongs to the host they are on. To pin a caseworker to their own kommune so they
can never see another tenant (even by visiting its host), bind them to one or
more tenants. The binding reuses the `domain_access` **Domain Access** field
(`field_domain_access`) already on `/user/{id}/edit` - the same pattern the OS2
`os2udoglaer` project uses. A bound caseworker gets an empty inbox, denied
records, and a 403 on the case pages of any host that is not theirs; unbound
users keep the host-based behavior and `bypass tenant isolation` operators see
across.

Bindings can be set manually on the user form, or auto-applied at login: under
`/admin/config/aabenforms/tenant` map a login claim (e.g. `cvr`) to a tenant id.
A CVR claim identifies a whole kommune, so magistrat-level binding stays manual.

## Usage

### ServiceplatformenClient

```php
// Inject service
$client = \Drupal::service('aabenforms_core.serviceplatformen_client');

// CPR lookup (SF1520)
try {
  $response = $client->request('SF1520', 'PersonLookup', [
    'cpr_number' => '0101001234',
  ]);

  $personName = $response['PersonGivenName'] . ' ' . $response['PersonSurnameName'];
}
catch (ServiceplatformenException $e) {
  // Handle error
  \Drupal::logger('mymodule')->error('CPR lookup failed: @error', [
    '@error' => $e->getMessage(),
  ]);
}
```

### EncryptionService

```php
// Inject service
$encryption = \Drupal::service('aabenforms_core.encryption_service');

// Encrypt CPR number
$encrypted_cpr = $encryption->encryptCpr('0101001234');

// Store encrypted value in database
$entity->set('encrypted_cpr', $encrypted_cpr);
$entity->save();

// Decrypt when needed
$cpr = $encryption->decryptCpr($entity->get('encrypted_cpr')->value);
```

### AuditLogger

```php
// Inject service
$audit = \Drupal::service('aabenforms_core.audit_logger');

// Log CPR lookup
$audit->logCprLookup(
  $cpr_number,
  'citizen_complaint_workflow',
  'success',
  ['workflow_id' => $workflow->id(), 'tenant_id' => 'aarhus']
);

// Retrieve audit logs
$logs = $audit->getAuditLog([
  'action' => 'cpr_lookup',
  'uid' => $user->id(),
], limit: 50);
```

### TenantResolver

```php
// Inject service
$tenant = \Drupal::service('aabenforms_core.tenant_resolver');

// Get current tenant
$tenant_id = $tenant->getCurrentTenantId(); // 'aarhus', 'odense', or NULL

// Get tenant-specific config
$mitid_client_id = $tenant->getTenantConfig('mitid.client_id');

// Check if multi-tenant
if ($tenant->isMultiTenant()) {
  $tenant_name = $tenant->getTenantName(); // 'Aarhus Kommune'
}
```

## Services

All services are available via dependency injection:

```yaml
services:
  my_module.my_service:
    class: Drupal\my_module\Service\MyService
    arguments:
      - '@aabenforms_core.serviceplatformen_client'
      - '@aabenforms_core.encryption_service'
      - '@aabenforms_core.audit_logger'
      - '@aabenforms_core.tenant_resolver'
```

## Database Schema

### `aabenforms_audit_log` Table

GDPR-compliant audit log for all sensitive data access.

| Field | Type | Description |
|-------|------|-------------|
| `id` | serial | Primary key |
| `uid` | int | User ID (0 for workflow/anonymous) |
| `action` | varchar(64) | Action type (cpr_lookup, cvr_lookup, etc.) |
| `identifier_hash` | varchar(64) | SHA-256 hash of identifier (CPR, CVR) |
| `purpose` | varchar(255) | Purpose of access (workflow name, form ID) |
| `status` | varchar(32) | Result status (success, failure, etc.) |
| `ip_address` | varchar(45) | Client IP address |
| `context` | text | Additional context as JSON |
| `timestamp` | int | Unix timestamp |

**Indexes**: `action`, `uid`, `timestamp`, `identifier_hash`

**Retention**: Audit logs should be retained according to organizational policy (typically 3-5 years for GDPR compliance).

## Security Considerations

### CPR Number Handling

**CRITICAL**: CPR numbers are sensitive personal data under GDPR Article 9.

**Always**:
- Encrypt CPR numbers at rest using EncryptionService
- Log all CPR lookups using AuditLogger
- Only request CPR when absolutely necessary
- Delete CPR data when no longer needed
- Use HTTPS for all Serviceplatformen requests

**Never**:
- Store CPR numbers in plain text
- Log CPR numbers in application logs
- Display CPR numbers in URLs or JavaScript
- Share CPR data across tenant boundaries

### Serviceplatformen Certificates

Production certificates must be:
- Stored securely (outside webroot)
- Protected with appropriate file permissions (600)
- Managed via Key module (not in version control)

## Testing

```bash
# Run unit tests
ddev test --group aabenforms_core

# Run with coverage
ddev test-coverage --group aabenforms_core
```

## Troubleshooting

### "Encryption profile not found"
- Ensure `aabenforms_aes256` encryption profile is created
- Check Key module configuration

### "Serviceplatformen connection failed"
- Verify service URLs in configuration
- Check certificate paths and permissions
- Test with `exttest.serviceplatformen.dk` endpoints first

### "Audit log not recording"
- Check database table exists: `drush sql:query "DESCRIBE aabenforms_audit_log"`
- Verify database permissions

## Related Modules

- **aabenforms_mitid** - MitID authentication (requires aabenforms_core)
- **aabenforms_cpr** - SF1520 CPR lookup (requires aabenforms_core)
- **aabenforms_cvr** - SF1530 CVR lookup (requires aabenforms_core)
- **aabenforms_digital_post** - SF1601 Digital Post (requires aabenforms_core)

## References

- **Serviceplatformen**: https://digitaliser.dk/group/42063
- **GDPR Compliance**: https://www.datatilsynet.dk/
- **Encrypt Module**: https://www.drupal.org/project/encrypt
- **Key Module**: https://www.drupal.org/project/key
- **Domain Module**: https://www.drupal.org/project/domain

## License

GPL-2.0 (aligned with Drupal and OS2 ecosystem)

## Maintainer

Mads Nørgaard <mads@aabenforms.dk>
