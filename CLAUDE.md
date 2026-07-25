# ÅbenForms Backend - Drupal 11

## Project Overview

**ÅbenForms** is a headless workflow automation platform for Danish municipalities, built on:
- **Backend**: Drupal 11.3.10 (this repository)
- **Frontend**: Nuxt 3 (separate repository)
- **Deployment**: contabo-infrastructure orchestration (separate repository, deploys to VPS2)

This backend provides:
- JSON:API endpoints for headless content delivery
- ECA (Event-Condition-Action) workflow engine (modern replacement for Maestro)
- Multi-tenancy via Domain module
- Danish government service integrations (MitID, Serviceplatformen, Digital Post)
- GDPR-compliant data handling with field-level encryption
- Modular SF1601 Digital Post (`aabenforms_digital_post` + ECA bridge submodule, plug-and-play on bare Drupal 11)
- Shared admin design tokens via `aabenforms_core/admin` library (CSS custom properties)

## Active workstream

Modular Digital Post + NemLogin rewrite. Goal: each Danish-government integration installs cleanly on any modern Drupal 11 with at most one mainstream contrib (`drupal:key`), with no dependency on proprietary alternative stacks.

- **Session 1 (shipped)**: `aabenforms_digital_post` core (DTOs, sender service, fake_db / wiremock transports, Drush, settings form, log table). Runs on the demo in `fake_db` mode. No live MeMo/SOAP and no idempotency yet.
- **Session 2A (shipped)**: `aabenforms_digital_post_eca` submodule (plugin id `aabenforms_digital_post_send`). `citizen_service_application.bpmn` Approved + Rejected branches both wired.
- **Session 2B (planned, issue #77)**: real MeMo XML + SF1601 SOAP via `itk-dev/serviceplatformen`; `live_test` against Serviceplatformen exttest endpoint.
- **Session 2C (planned, issue #79)**: `aabenforms_nemlogin` OIDC core + Keycloak preset + shim `aabenforms_mitid` over it. These modules do not exist yet.
- **Session 3 (planned)**: webform / beskedfordeler (#78) / key bridges, advanced queue, examples, remove `aabenforms_mitid`, bare-D11 GitHub Actions verification.

Recent platform-wide fixes:
- `aabenforms_log` shim replaces removed upstream `eca_base_log`/`eca_base_mail`. `aabenforms_workflows_update_11001` migrates saved configs.
- `hook_storage_transform_import` in `aabenforms_workflows.module` preserves wizard-created configs across `drush cim` (without it, every deploy nuked admin-created workflows).
- Wizard step indicator modernized (numbered circles + track line) using `--af-*` tokens from `aabenforms_core/admin`.
- TemplateBrowserController preview thumbnails fixed (controller now emits `data-xml` on canvas) + Active Workflows section moved on top when non-empty.
- TemplateSelectForm: `disableCache()` to dodge File-object serialization on rebuild + path-traversal hardening on import.

## Technology Stack

| Component | Version | Purpose |
|-----------|---------|---------|
| Drupal Core | 11.3.10 | CMS foundation |
| PHP | 8.4 | Runtime |
| MariaDB | (DDEV default) | Database (consistent local to production) |
| DDEV | Latest | Local development |
| Composer | 2.x | Dependency management |
| Drush | 13.7 | CLI tool |

## Essential Commands

### DDEV Operations
```bash
# Start environment
cd /home/mno/ddev-projects/aabenforms/backend
ddev start

# Stop environment
ddev stop

# SSH into container
ddev ssh

# Database operations
ddev drush sql:dump > backup.sql
ddev import-db --file=backup.sql
```

### Drush Commands
```bash
# Clear cache
ddev drush cr

# Export/Import configuration
ddev drush config:export -y
ddev drush config:import -y

# Check database updates
ddev drush updatedb -y

# User operations
ddev drush user:login    # Generate one-time login link

# Module operations
ddev drush pm:enable aabenforms_tenant
ddev drush pm:uninstall <module_name>
```

### Composer Operations
```bash
# Add module
ddev composer require drupal/<module_name>

# Remove module
ddev composer remove drupal/<module_name>

# Update all dependencies
ddev composer update

# Security updates
ddev composer update drupal/core --with-dependencies
```

## Module Architecture

### Custom Modules (Package: ÅbenForms)

These are the modules that actually exist today under `web/modules/custom/`:

```
web/modules/custom/
├── aabenforms_core/              # Foundation: shared services, Serviceplatformen
│   │                            #   client, field-level CPR encryption (AES-256),
│   │                            #   GDPR audit logging, admin design tokens
│   ├── aabenforms_core.info.yml
│   ├── aabenforms_core.module
│   └── src/
│
├── aabenforms_tenant/            # Multi-tenancy (depends on: domain)
│   ├── aabenforms_tenant.info.yml
│   └── src/
│
├── aabenforms_workflows/         # ECA integration + Workflow Modeler editor
│   │                            #   13 workflow templates (.bpmn source files),
│   │                            #   21 ECA action plugins (incl. CPR/CVR lookup)
│   ├── aabenforms_workflows.info.yml
│   ├── aabenforms_workflows.module
│   └── workflows/                # .bpmn template source files
│
├── aabenforms_webform/           # Custom webform elements with server-side
│   └──                          #   validation: CPR (modulus-11), CVR, Adressevælger address
│
├── aabenforms_mitid/             # MitID OIDC sign-in (against a Keycloak mock IdP)
│   └──                          #   Fails closed by default; demo mode gated by
│                                #   allow_mitid_demo_mode
│
└── aabenforms_digital_post/      # SF1601 Digital Post core (DTOs, sender service)
    └── modules/
        ├── aabenforms_digital_post_eca/            # ECA bridge submodule
        └── aabenforms_digital_post_session_inbox/  # Session inbox submodule
```

Planned, not yet implemented (do not assume these exist): `aabenforms_nemlogin`
(+ `_keycloak`), `aabenforms_digital_post_webform`, `aabenforms_digital_post_beskedfordeler`,
`aabenforms_digital_post_os2web_key`, `aabenforms_cpr`, `aabenforms_cvr`, `aabenforms_webform`,
`aabenforms_gdpr`, `aabenforms_sbsys`, `aabenforms_get_organized`. See the GitHub issue
backlog (#72-#92) for status.

Notes on the "standalone module" framing:
- CPR (SF1520) and CVR (SF1530) lookup are ECA action plugins inside `aabenforms_workflows`
  plus a Serviceplatformen client in `aabenforms_core` - not standalone modules.
- Adressevælger address autocomplete is a webform element in `aabenforms_webform` - not a module.
- Field-level CPR encryption and GDPR audit logging are built into `aabenforms_core`. There
  is no retention / right-to-erasure subsystem yet (planned, issue #91).

### Module Dependencies (existing modules)

```
aabenforms_core (no aabenforms dependencies)
  ├─→ aabenforms_tenant (depends on: domain)
  ├─→ aabenforms_workflows (depends on: eca, webform; eca 3.1 also needs modeler_api)
  ├─→ aabenforms_webform (depends on: webform, aabenforms_core)
  ├─→ aabenforms_mitid (depends on: openid_connect, aabenforms_core)
  └─→ aabenforms_digital_post (depends on: key, aabenforms_core)
        ├─→ aabenforms_digital_post_eca (depends on: eca)
        └─→ aabenforms_digital_post_session_inbox
```

## Contrib Modules

| Module | Version | Purpose |
|--------|---------|---------|
| **Workflow Engine** | | |
| eca | 3.1.1 | Event-Condition-Action engine (depends on modeler_api) |
| modeler | 1.0.3 | Workflow Modeler editor (adopted editor for all flows) |
| bpmn_io | 3.0.5 | Present, but the Workflow Modeler is the adopted editor |
| webform | 6.3.0-beta8 | Dynamic form builder |
| **Multi-Tenancy** | | |
| domain | 2.0.1 | URL-based tenant routing |
| domain_access | 2.0.1 | Per-tenant content isolation |
| **Security** | | |
| key | 1.22.0 | Key management |
| **API** | | |
| jsonapi | Core | JSON:API implementation |
| jsonapi_extras | 3.28.0 | JSON:API enhancements |
| **Authentication** | | |
| openid_connect | 3.x | OpenID Connect (for MitID) |
| externalauth | 2.x | External authentication framework |
| **Admin** | | |
| gin | 5.0.12 | Admin theme |
| gin_toolbar | 2.x | Toolbar |

## Danish Government Integrations

### Today (real, against test/mock endpoints)
- **aabenforms_mitid**: MitID OIDC sign-in against a Keycloak mock IdP. No live registration;
  fails closed by default, demo mode behind `allow_mitid_demo_mode`.
- **CPR (SF1520) / CVR (SF1530) lookup**: action plugins in `aabenforms_workflows` plus a
  Serviceplatformen client in `aabenforms_core`. Run against test/WireMock; live access needs
  client certificates (planned, issue #76).
- **Adressevælger / CPR / CVR webform elements**: custom elements in `aabenforms_webform` with
  server-side validation (CPR modulus-11, CVR, Adressevælger).
- **Field-level CPR encryption + audit logging**: built into `aabenforms_core`.
- **aabenforms_digital_post** (SF1601): runs in `fake_db` / `wiremock` test modes. No live
  MeMo/SOAP and no idempotency yet (planned, issues #73, #77).

### Planned (not yet implemented - see GitHub backlog #72-#92)
- Live Serviceplatformen certs + enrollment for SF1520/SF1530 (#76)
- Real SF1601 MeMo builder + SOAP transport (#77)
- Beskedfordeler SF1461/SF1462 delivery receipts (#78)
- MitID / NemLog-in token validation + production registration (#79)
- Adressevælger + Datafordeler (BBR/Matriklen) server-side validation (#80)
- Case-handoff / ESDH adapters and SF1470 journaling (#84-#86)
- GDPR retention + right-to-erasure subsystem (#91)

Payment, SMS, GIS/zoning, payroll and calendar/booking actions are currently demo mocks, not
production integrations (#81-#83).

## Development Workflow

### 1. Creating New Features
```bash
# 1. Create feature branch
git checkout -b feature/add-digital-post

# 2. Enable development mode
ddev drush state:set system.maintenance_mode TRUE

# 3. Make changes, enable modules
ddev drush pm:enable aabenforms_digital_post

# 4. Export configuration
ddev drush config:export -y

# 5. Test
ddev drush updatedb -y && ddev drush cr

# 6. Disable maintenance mode
ddev drush state:set system.maintenance_mode FALSE

# 7. Commit
git add -A && git commit -m "Add Digital Post integration"
```

### 2. Updating Contrib Modules
```bash
# Check for security updates
ddev composer outdated "drupal/*"

# Update specific module
ddev composer update drupal/webform --with-dependencies
ddev drush updatedb -y
ddev drush config:export -y

# Test thoroughly, then commit
git add composer.json composer.lock config/
git commit -m "Update webform to 6.3.1"
```

### 3. Testing Multi-Tenancy
```bash
# Provision tenants (Domain + per-tenant CPR key + encryption profile) in one
# command each. A tenant can be a whole kommune or a single magistrat.
ddev drush aabenforms:tenant:provision aarhus_mtm "Aarhus - Teknik og Miljoe" \
  --hostname=mtm.aarhus.aabenforms.ddev.site
ddev drush aabenforms:tenant:provision odense "Odense Kommune" \
  --hostname=odense.aabenforms.ddev.site

# Each tenant's CPR key uses the env provider; set its variable (base64 of 32
# random bytes) and restart before storing CPR, e.g.:
#   AABENFORMS_CPR_KEY_AARHUS_MTM, AABENFORMS_CPR_KEY_ODENSE

# Add to /etc/hosts (or DDEV auto-manages with use_dns_when_possible)
# 127.0.0.1 mtm.aarhus.aabenforms.ddev.site
# 127.0.0.1 odense.aabenforms.ddev.site
```

## Workflow Development

The visual editor is the **Workflow Modeler** provided by `drupal/modeler` (React Flow /
Modeler API, modeler_id `workflow_modeler`). It is the adopted editor for all flows. `bpmn_io`
is installed but is not the editor used for the flows. Workflow templates are stored as `.bpmn`
source files (BPMN is the on-disk template format, not the editor).

### BpmnTemplateManager Service

The `BpmnTemplateManager` service provides centralized template management:

```php
// Get the service
$templateManager = \Drupal::service('aabenforms_workflows.bpmn_template_manager');

// List available templates
$templates = $templateManager->getAvailableTemplates();
// Returns: ['building_permit', 'contact_form', 'company_verification', ...]

// Load a template
$xml = $templateManager->loadTemplate('building_permit');

// Validate BPMN XML
$isValid = $templateManager->validateTemplate($xml);
if (!$isValid) {
  $errors = $templateManager->getValidationErrors();
}

// Save custom template
$templateManager->saveTemplate('my_custom_workflow', $customXml);

// Export template
$exported = $templateManager->exportTemplate('building_permit');
file_put_contents('/tmp/workflow.bpmn', $exported);
```

### Creating Custom BPMN Templates

There are 13 ready-made workflow templates (BPMN source files) stored in
`web/modules/custom/aabenforms_workflows/workflows/`, and 18 ECA flows deployed under
`config/sync/eca.eca.*`. A template file looks like this:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"
                  xmlns:eca="https://drupal.org/project/eca"
                  id="my_workflow">
  <bpmn:process id="Process_1" name="My Custom Workflow">

    <!-- Start Event -->
    <bpmn:startEvent id="StartEvent_1" name="Form Submitted">
      <bpmn:outgoing>Flow_1</bpmn:outgoing>
    </bpmn:startEvent>

    <!-- Service Task with ECA Action -->
    <bpmn:serviceTask id="Task_MitID" name="Validate MitID"
                      eca:action="aabenforms_mitid_validate">
      <bpmn:incoming>Flow_1</bpmn:incoming>
      <bpmn:outgoing>Flow_2</bpmn:outgoing>
    </bpmn:serviceTask>

    <!-- User Task -->
    <bpmn:userTask id="Task_Review" name="Manual Review">
      <bpmn:incoming>Flow_2</bpmn:incoming>
      <bpmn:outgoing>Flow_3</bpmn:outgoing>
    </bpmn:userTask>

    <!-- End Event -->
    <bpmn:endEvent id="EndEvent_1" name="Workflow Complete">
      <bpmn:incoming>Flow_3</bpmn:incoming>
    </bpmn:endEvent>

    <!-- Sequence Flows -->
    <bpmn:sequenceFlow id="Flow_1" sourceRef="StartEvent_1" targetRef="Task_MitID"/>
    <bpmn:sequenceFlow id="Flow_2" sourceRef="Task_MitID" targetRef="Task_Review"/>
    <bpmn:sequenceFlow id="Flow_3" sourceRef="Task_Review" targetRef="EndEvent_1"/>

  </bpmn:process>
</bpmn:definitions>
```

### ECA Action Plugins

ÅbenForms provides 21 custom ECA action plugins (non-test) in `aabenforms_workflows` for Danish
workflows. A few representative examples:

#### 1. MitID Validation (`aabenforms_mitid_validate`)
```php
// Validates MitID authentication token
// Configuration:
// - token: MitID token from form submission
// - level: Required assurance level (SUBSTANTIAL, HIGH)
// Returns: User CPR, name, validation timestamp
```

#### 2. CPR Lookup (`aabenforms_cpr_lookup`)
```php
// Queries Serviceplatformen SF1520 for person data
// Configuration:
// - cpr: CPR number (encrypted)
// - fields: Data fields to retrieve (address, family, etc.)
// Returns: Person master data, address history
```

#### 3. CVR Lookup (`aabenforms_cvr_lookup`)
```php
// Queries Serviceplatformen SF1530 for company data
// Configuration:
// - cvr: CVR number
// - include_units: Include P-numbers (production units)
// Returns: Company data, industry codes, ownership
```

#### 4. Audit Log (`aabenforms_audit_log`)
```php
// Logs workflow actions for GDPR compliance
// Configuration:
// - action: Action performed (e.g., "cpr_lookup")
// - entity_id: Related entity
// - metadata: Additional context
// Stored in: database table 'aabenforms_audit_log'
```

### Template Validation

The template manager validates BPMN XML against BPMN 2.0 schema:

```php
$templateManager = \Drupal::service('aabenforms_workflows.bpmn_template_manager');

$xml = file_get_contents('my_workflow.bpmn');
$isValid = $templateManager->validateTemplate($xml);

if (!$isValid) {
  $errors = $templateManager->getValidationErrors();
  foreach ($errors as $error) {
    \Drupal::logger('aabenforms')->error('BPMN validation error: @error', [
      '@error' => $error->message,
    ]);
  }
}
```

Common validation errors:
- Invalid XML structure
- Missing required BPMN elements (process, startEvent, endEvent)
- Invalid sequence flow references
- Unknown ECA action plugin in serviceTask

### Importing/Exporting via Admin UI

1. Navigate to **Configuration > Workflows > BPMN Templates** (`/admin/config/workflow/bpmn-templates`)
2. Click "Import Template"
3. Upload `.bpmn` XML file or paste XML content
4. Preview diagram (uses bpmn.io renderer)
5. Save template with unique ID

To export:
1. Select template from list
2. Click "Export"
3. Download as `.bpmn` file
4. Share with other installations or version control

## Security & GDPR Notes

### CPR Number Handling
 **CRITICAL**: CPR numbers (Danish social security numbers) are **sensitive personal data** under GDPR Article 9.

**Requirements**:
1. **Field-level encryption** built into `aabenforms_core`
2. **Access logging** - all CPR lookups must be audited
3. **Data minimization** - only request CPR when absolutely necessary
4. **Retention policies** - planned, not yet implemented (issue #91)
5. **Consent management** - explicit user consent required

### Encryption Setup
```bash
# Generate encryption key
ddev drush key:generate aes encryption --key-type=encryption --key-provider=config

# Configure field encryption
ddev drush config:set encrypt.profile.cpr_encryption encryption_key aes
```

### Audit Logging
All CPR lookups via the CPR lookup action plugin are automatically logged:
- User ID
- Timestamp
- CPR queried
- Purpose (from workflow context)
- IP address

## API Endpoints

### JSON:API Base
```
https://aabenforms.ddev.site/jsonapi
```

### Key Resources
```
# Webform schemas
GET /jsonapi/webform/webform/{form_id}

# Submissions
POST /jsonapi/webform_submission/{form_id}

# Tenant configuration
GET /jsonapi/node/tenant?filter[domain]={domain}

# Workflow tasks
GET /jsonapi/eca_workflow/task?filter[assigned_to]=current_user
```

### CORS Configuration
Configured in `.ddev/config.yaml`:
```yaml
web_environment:
  - CORS_ALLOW_ORIGIN=https://aabenforms-frontend.ddev.site
```

## Database

**Type**: MariaDB (DDEV default)
**Why**: Consistent across local and production environments

```bash
# Connect to database
ddev mysql

# Database snapshot
ddev snapshot

# Restore snapshot
ddev snapshot restore --latest
```

## Performance

### Caching Strategy
```bash
# Clear all caches
ddev drush cr

# Clear specific cache bin
ddev drush cache:clear render

# Check cache settings
ddev drush config:get system.performance
```

### Production Recommendations
- Enable Redis or Memcached
- Enable Drupal performance caching
- Put a reverse-proxy cache or CDN in front
- Lazy-load Webform fields
- Index frequently queried fields

## Troubleshooting

### Common Issues

**Problem**: "Class not found" errors
**Solution**: Rebuild autoloader
```bash
ddev composer dump-autoload
ddev drush cr
```

**Problem**: Database connection errors
**Solution**: Restart DDEV
```bash
ddev restart
```

**Problem**: Permission denied on files
**Solution**: Fix file permissions
```bash
ddev exec chmod -R 777 web/sites/default/files
```

**Problem**: Config import fails
**Solution**: Check UUID mismatch
```bash
ddev drush config:status
ddev drush config:delete <config_name>
ddev drush config:import -y
```

## Git Workflow

```bash
# Clone repository
git clone https://github.com/madsnorgaard/aabenforms.git backend

# Create feature branch
git checkout -b feature/<name>

# Standard commit flow
git add <files>
git commit -m "Descriptive message"
git push origin feature/<name>

# Merge to main (after review)
git checkout main
git merge feature/<name>
git push origin main
```

## Related Repositories

- **Frontend**: [madsnorgaard/aabenforms-frontend](https://github.com/madsnorgaard/aabenforms-frontend)
- **Platform**: [madsnorgaard/aabenforms-platform](https://github.com/madsnorgaard/aabenforms-platform)

---

## Workflow Development

### Creating New ECA Action Plugins

ÅbenForms workflows use custom ECA action plugins for Danish integrations.

**Location**: `web/modules/custom/aabenforms_workflows/src/Plugin/Action/`

**Base Class**: All actions extend `AabenFormsActionBase`

**Example**: Create a new action plugin

```php
<?php

namespace Drupal\aabenforms_workflows\Plugin\Action;

use Drupal\eca\Plugin\Action\ConfigurableActionBase;

/**
 * Sends SMS notification via Danish SMS gateway.
 *
 * @Action(
 *   id = "aabenforms_send_sms",
 *   label = @Translation("Send SMS Notification"),
 *   description = @Translation("Sends SMS via Danish SMS gateway"),
 *   type = "entity"
 * )
 */
class SendSmsAction extends AabenFormsActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $phone = $this->tokenService->replaceClear($this->configuration['phone']);
    $message = $this->tokenService->replaceClear($this->configuration['message']);

    // Call SMS service
    $this->smsService->send($phone, $message);

    // Log action
    $this->logger->info('SMS sent to @phone', ['@phone' => $phone]);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'phone' => '',
      'message' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['phone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phone Number'),
      '#default_value' => $this->configuration['phone'],
      '#description' => $this->t('Danish phone number (e.g., +4512345678)'),
      '#required' => TRUE,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#default_value' => $this->configuration['message'],
      '#description' => $this->t('SMS message (max 160 characters)'),
      '#required' => TRUE,
    ];

    return $form;
  }
}
```

**Testing Actions**:
```bash
# Run action tests
ddev exec phpunit web/modules/custom/aabenforms_workflows/tests/src/Unit/Plugin/Action/

# Test specific action
ddev exec phpunit --filter=SendSmsActionTest
```

### Template Development

**Creating New BPMN Templates**:

1. **Use the Workflow Modeler** (drupal/modeler, modeler_id `workflow_modeler`):
   ```
   Navigate to: /admin/config/workflow/eca
   Create a new model with the Workflow Modeler, or import a .bpmn template file
   ```

2. **Template Structure**:
   ```xml
   <?xml version="1.0" encoding="UTF-8"?>
   <bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"
                      id="Definitions_my_template"
                      targetNamespace="http://aabenforms.dk/bpmn">

     <bpmn:process id="my_process" name="My Workflow" isExecutable="true">
       <bpmn:documentation>[category: municipal]
       Description of workflow purpose and use case.
       </bpmn:documentation>

       <bpmn:startEvent id="StartEvent_1" name="Start">
         <bpmn:outgoing>Flow_1</bpmn:outgoing>
       </bpmn:startEvent>

       <!-- Add tasks, gateways, events -->

       <bpmn:endEvent id="EndEvent_1" name="Complete">
         <bpmn:incoming>Flow_Final</bpmn:incoming>
       </bpmn:endEvent>

       <!-- Sequence flows -->
     </bpmn:process>
   </bpmn:definitions>
   ```

3. **Save Template**:
   ```bash
   # Save to workflows directory
   cp my_template.bpmn web/modules/custom/aabenforms_workflows/workflows/

   # Validate template
   ddev drush aabenforms:validate-template my_template
   ```

4. **Add Metadata**:
   Include category in documentation element:
   - `[category: municipal]` - Complex municipal workflows
   - `[category: citizen_service]` - Simple citizen interactions
   - `[category: verification]` - Automated verifications

**Template Best Practices**:
- Always include start and end events
- Add meaningful names to all elements
- Document decision gateway conditions
- Use boundary events for timeouts
- Include audit logging tasks
- Test with BpmnTemplateManager service

### Testing Workflows

**Local Testing Workflow**:

```bash
# 1. Create test data
ddev drush aabenforms:create-test-data

# 2. Test workflow execution
ddev drush aabenforms:test-workflow building_permit

# 3. Simulate parent approval
ddev drush aabenforms:simulate-approval --workflow=daycare_enrollment \
  --parent1=approve --parent2=approve

# 4. View audit logs
ddev drush aabenforms:audit-log --workflow=daycare_enrollment --limit=50
```

**Testing with Mock Services**:

Mock Danish services are available in test environment:
- **Mock MitID**: Test authentication without real MitID
- **Mock CPR**: Test person lookups with fake CPR numbers
- **Mock CVR**: Test company lookups with fake CVR numbers
- **Mock Digital Post**: Test notifications without real delivery

See: [docs/DDEV_MOCK_SERVICES_GUIDE.md](docs/DDEV_MOCK_SERVICES_GUIDE.md)

**Integration Tests**:

```php
<?php

namespace Drupal\Tests\aabenforms_workflows\Kernel\Integration;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests multi-party workflow execution.
 */
class MultiPartyWorkflowTest extends KernelTestBase {

  protected static $modules = [
    'aabenforms_workflows',
    'eca',
    'webform',
  ];

  /**
   * Test dual parent approval flow.
   */
  public function testDualParentApprovalFlow(): void {
    // Create test workflow
    $workflow = $this->createTestWorkflow('daycare_enrollment');

    // Submit request
    $submission = $this->submitWebform([
      'child_name' => 'Test Barn',
      'parent1_email' => 'parent1@test.dk',
      'parent2_email' => 'parent2@test.dk',
    ]);

    // Simulate parent 1 approval
    $this->simulateApproval($submission, 'parent1', TRUE);

    // Verify workflow continues
    $this->assertWorkflowStatus($submission, 'awaiting_parent2');

    // Simulate parent 2 approval
    $this->simulateApproval($submission, 'parent2', TRUE);

    // Verify workflow proceeds to case worker
    $this->assertWorkflowStatus($submission, 'case_worker_review');
  }
}
```

### Workflow Architecture

**Three-Workflow Pattern**:

ÅbenForms uses a three-workflow parallel approval pattern for dual-parent approvals:

```
Main Workflow: Orchestrates overall process
    │
    ├─ Workflow 1: Parent 1 Approval
    │  ├─ MitID Authentication
    │  ├─ CPR Lookup
    │  ├─ Present Information
    │  └─ Capture Decision
    │
    ├─ Workflow 2: Parent 2 Approval
    │  ├─ MitID Authentication
    │  ├─ CPR Lookup
    │  ├─ Present Information
    │  └─ Capture Decision
    │
    └─ Synchronization Point
       └─ Both Approved? → Case Worker Review
```

**Benefits**:
- Parallel processing (faster)
- Independent auth sessions
- Separate data visibility rules
- Isolated failure handling

**Implementation**:
```yaml
# In ECA model
- event: webform_submission:insert
  conditions:
    - type: webform_id
      value: parent_request_form
  actions:
    - type: trigger_subprocess
      subprocess: parent1_approval_workflow
      token: workflow1_id
    - type: trigger_subprocess
      subprocess: parent2_approval_workflow
      token: workflow2_id
    - type: wait_for_subprocesses
      tokens: [workflow1_id, workflow2_id]
    - type: evaluate_results
      condition: all_approved
```

### Workflow Debugging

**Enable Debug Logging**:
```bash
# Enable ECA debug mode
ddev drush config:set eca.settings debug TRUE

# Enable workflow logging
ddev drush config:set aabenforms_workflows.settings log_level debug

# Clear cache
ddev drush cr
```

**View Workflow Execution Logs**:
```bash
# Real-time log monitoring
ddev logs -f

# Workflow-specific logs
ddev drush watchdog:show --filter=aabenforms_workflows

# Export logs for analysis
ddev drush watchdog:show --format=json > workflow_logs.json
```

**Common Debugging Scenarios**:

1. **Workflow Not Triggering**:
   ```bash
   # Check event subscriptions
   ddev drush eca:list

   # Verify webform ID matches
   ddev drush webform:list

   # Test event manually
   ddev drush eca:trigger webform_submission:insert --id=123
   ```

2. **Token Not Resolving**:
   ```bash
   # List available tokens in context
   ddev drush eca:tokens --context=webform_submission

   # Test token replacement
   ddev drush eca:token-replace "[webform_submission:field_child_name]" --id=123
   ```

3. **Action Failing Silently**:
   ```php
   // Add debug logging to action
   $this->logger->debug('Action config: @config', [
     '@config' => json_encode($this->configuration),
   ]);
   ```

---

## Support

- **Issues**: https://github.com/madsnorgaard/aabenforms/issues
- **Drupal Docs**: https://www.drupal.org/docs
- **ECA Docs**: https://www.drupal.org/docs/contributed-modules/eca-event-driven-actions
- **Domain Docs**: https://www.drupal.org/docs/contributed-modules/domain-access
- **BPMN Spec**: https://www.omg.org/spec/BPMN/2.0/

## Documentation

**For Municipal Administrators**:
- [Municipal Admin Guide](docs/MUNICIPAL_ADMIN_GUIDE.md)
- [Workflow Creation Tutorial](docs/tutorials/CREATE_APPROVAL_WORKFLOW.md)
- [Approval Process Guide](docs/APPROVAL_PROCESS_GUIDE.md)
- [Workflow Templates Reference](docs/WORKFLOW_TEMPLATES.md)
- [Quick Reference Card](docs/QUICK_REFERENCE.md)

**For Developers**:
- [CLAUDE.md](CLAUDE.md) - This file
- [CI/CD Strategy](docs/CI_CD_STRATEGY.md)
- [Mock Services Guide](docs/DDEV_MOCK_SERVICES_GUIDE.md)

## License

GPL-2.0-or-later - See LICENSE file
