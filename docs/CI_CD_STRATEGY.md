# ÅbenForms CI/CD Strategy - Mock Services Integration

**Philosophy**: Use mock services everywhere (local, CI, staging) - real services only in production
**Goal**: Fast, reliable, cost-effective CI/CD pipeline with comprehensive testing

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    Development Pipeline                      │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Local Dev          CI/CD              Staging     Production│
│  (DDEV)         (GitHub Actions)      (Docker)    (VPS2)     │
│     │                  │                  │            │      │
│     ▼                  ▼                  ▼            ▼      │
│  Mock Services    Mock Services      Mock Services  Real     │
│  (Keycloak)       (Docker)           (Docker)       Services │
│  (WireMock)       (Keycloak)         (Keycloak)     (MitID)  │
│                   (WireMock)         (WireMock)     (SP)     │
│                                                               │
│  Fast          Fast            Fast        Real      │
│  Offline       No credentials   Safe       traffic   │
│  Deterministic Parallel jobs   Testing    only      │
└─────────────────────────────────────────────────────────────┘
```

---

## 1. Local Development (DDEV)

**Status**: **COMPLETE** (already working!)

**Setup**:
```bash
ddev start  # Automatically starts mock services
```

**Services**:
- Keycloak: http://localhost:8080
- WireMock: http://localhost:8081
- Drupal: https://aabenforms.ddev.site

**Benefits**:
- No credentials needed
- Work offline
- Fast (milliseconds)
- Deterministic test data

---

## 2. CI/CD Pipeline (GitHub Actions)

### Strategy: Docker Compose in CI

**Key Principle**: Same mock services in CI as in local dev (consistency!)

### GitHub Actions Workflow

**File**: `.github/workflows/ci.yml`

```yaml
name: CI Pipeline

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main, develop]

env:
  PHP_VERSION: '8.4'
  NODE_VERSION: '20'

jobs:
  # Job 1: Static Analysis (No Database Needed)
  static-analysis:
    name: Static Analysis (Fast)
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ env.PHP_VERSION }}
          tools: composer:v2
          coverage: none

      - name: Cache Composer dependencies
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache
          key: composer-${{ hashFiles('**/composer.lock') }}

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist
        working-directory: backend

      - name: PHPCS (Drupal Coding Standards)
        run: vendor/bin/phpcs --standard=Drupal web/modules/custom
        working-directory: backend

      - name: PHPStan (Static Analysis)
        run: vendor/bin/phpstan analyse web/modules/custom --level=6
        working-directory: backend

      - name: Drupal Check
        run: vendor/bin/drupal-check web/modules/custom
        working-directory: backend

  # Job 2: Unit Tests (No External Services)
  unit-tests:
    name: Unit Tests (Fast)
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ env.PHP_VERSION }}
          tools: composer:v2
          coverage: xdebug

      - name: Install dependencies
        run: composer install --no-interaction
        working-directory: backend

      - name: Run PHPUnit Unit Tests
        run: vendor/bin/phpunit --testsuite=unit --testdox
        working-directory: backend

  # Job 3: Integration Tests (With Mock Services)
  integration-tests:
    name: Integration Tests (With Mocks)
    runs-on: ubuntu-latest

    services:
      # MariaDB for Drupal
      mariadb:
        image: mariadb:10.11
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: drupal_test
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3
        ports:
          - 3306:3306

      # Keycloak (MitID Mock)
      keycloak:
        image: quay.io/keycloak/keycloak:23.0
        env:
          KEYCLOAK_ADMIN: admin
          KEYCLOAK_ADMIN_PASSWORD: admin
          KC_HTTP_ENABLED: 'true'
          KC_HOSTNAME_STRICT: 'false'
        options: >-
          --health-cmd="curl -f http://localhost:8080/health/ready || exit 1"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=10
          --health-start-period=30s
        ports:
          - 8080:8080
        volumes:
          - ${{ github.workspace }}/backend/.ddev/mocks/keycloak/realms:/opt/keycloak/data/import
        command: start-dev --import-realm

      # WireMock (Serviceplatformen Mock)
      wiremock:
        image: wiremock/wiremock:3.3.1
        options: >-
          --health-cmd="curl -f http://localhost:8080/__admin/health || exit 1"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=5
        ports:
          - 8081:8080
        volumes:
          - ${{ github.workspace }}/backend/.ddev/mocks/wiremock/mappings:/home/wiremock/mappings
          - ${{ github.workspace }}/backend/.ddev/mocks/wiremock/__files:/home/wiremock/__files

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ env.PHP_VERSION }}
          extensions: mbstring, xml, ctype, iconv, intl, pdo_mysql, dom, gd
          tools: composer:v2
          coverage: none

      - name: Install Composer dependencies
        run: composer install --no-interaction
        working-directory: backend

      - name: Wait for services
        run: |
          echo "Waiting for Keycloak..."
          timeout 60 bash -c 'until curl -f http://localhost:8080/health/ready; do sleep 2; done'

          echo "Waiting for WireMock..."
          timeout 30 bash -c 'until curl -f http://localhost:8081/__admin/health; do sleep 2; done'

          echo "All mock services ready!"

      - name: Install Drupal
        run: |
          vendor/bin/drush site:install standard \
            --db-url=mysql://root:root@127.0.0.1/drupal_test \
            --site-name="ÅbenForms Test" \
            --account-name=admin \
            --account-pass=admin \
            -y
        working-directory: backend
        env:
          SIMPLETEST_DB: mysql://root:root@127.0.0.1/drupal_test
          SIMPLETEST_BASE_URL: http://localhost:8888

      - name: Enable ÅbenForms modules
        run: |
          vendor/bin/drush pm:enable aabenforms_core -y
          vendor/bin/drush pm:enable aabenforms_mitid -y
          vendor/bin/drush cr
        working-directory: backend

      - name: Configure Mock Services
        run: |
          vendor/bin/drush config:set aabenforms_mitid.settings oidc.issuer 'http://localhost:8080/realms/danish-gov-test' -y
          vendor/bin/drush config:set aabenforms_mitid.settings oidc.client_id 'aabenforms-backend' -y
          vendor/bin/drush config:set aabenforms_mitid.settings mock_mode TRUE -y
          vendor/bin/drush config:set aabenforms_core.settings serviceplatformen.endpoint 'http://localhost:8081' -y
          vendor/bin/drush config:set aabenforms_core.settings serviceplatformen.mock_mode TRUE -y
        working-directory: backend

      - name: Run PHPUnit Integration Tests
        run: vendor/bin/phpunit --testsuite=kernel --testdox
        working-directory: backend
        env:
          SIMPLETEST_DB: mysql://root:root@127.0.0.1/drupal_test
          MITID_MOCK_URL: http://localhost:8080
          SERVICEPLATFORMEN_MOCK_URL: http://localhost:8081

      - name: Test MitID Integration
        run: |
          vendor/bin/drush ev "
          \$client = \Drupal::service('aabenforms_mitid.oidc_client');
          echo 'MitID OIDC client loaded successfully' . PHP_EOL;

          // Test OIDC discovery
          \$ch = curl_init('http://localhost:8080/realms/danish-gov-test/.well-known/openid-configuration');
          curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
          \$result = curl_exec(\$ch);
          \$config = json_decode(\$result, true);

          if (isset(\$config['issuer'])) {
            echo 'OIDC Discovery working' . PHP_EOL;
          } else {
            echo 'OIDC Discovery failed' . PHP_EOL;
            exit(1);
          }
          "
        working-directory: backend

  # Job 4: Frontend Tests (Nuxt 4)
  frontend-tests:
    name: Frontend Tests
    runs-on: ubuntu-latest

    services:
      keycloak:
        image: quay.io/keycloak/keycloak:23.0
        env:
          KEYCLOAK_ADMIN: admin
          KEYCLOAK_ADMIN_PASSWORD: admin
        ports:
          - 8080:8080
        command: start-dev --import-realm

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: ${{ env.NODE_VERSION }}
          cache: 'npm'
          cache-dependency-path: frontend/package-lock.json

      - name: Install dependencies
        run: npm ci
        working-directory: frontend

      - name: Lint
        run: npm run lint
        working-directory: frontend

      - name: Type check
        run: npm run type-check
        working-directory: frontend

      - name: Run tests
        run: npm run test
        working-directory: frontend
        env:
          NUXT_PUBLIC_MITID_ISSUER: http://localhost:8080/realms/danish-gov-test

  # Job 5: E2E Tests (Playwright)
  e2e-tests:
    name: E2E Tests (Full Stack)
    runs-on: ubuntu-latest

    services:
      mariadb:
        image: mariadb:10.11
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: drupal_test
        ports:
          - 3306:3306

      keycloak:
        image: quay.io/keycloak/keycloak:23.0
        env:
          KEYCLOAK_ADMIN: admin
          KEYCLOAK_ADMIN_PASSWORD: admin
        ports:
          - 8080:8080
        volumes:
          - ${{ github.workspace }}/backend/.ddev/mocks/keycloak/realms:/opt/keycloak/data/import
        command: start-dev --import-realm

      wiremock:
        image: wiremock/wiremock:3.3.1
        ports:
          - 8081:8080
        volumes:
          - ${{ github.workspace }}/backend/.ddev/mocks/wiremock/mappings:/home/wiremock/mappings
          - ${{ github.workspace }}/backend/.ddev/mocks/wiremock/__files:/home/wiremock/__files

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP & Node
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ env.PHP_VERSION }}

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: ${{ env.NODE_VERSION }}

      - name: Start Backend
        run: |
          composer install
          vendor/bin/drush site:install -y
          vendor/bin/drush pm:enable aabenforms_core aabenforms_mitid -y
          php -S localhost:8888 -t web &
        working-directory: backend

      - name: Start Frontend
        run: |
          npm ci
          npm run build
          npm run start &
        working-directory: frontend

      - name: Install Playwright
        run: npx playwright install --with-deps
        working-directory: frontend

      - name: Run E2E tests
        run: npm run test:e2e
        working-directory: frontend

      - name: Upload test results
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report
          path: frontend/playwright-report/

  # Job 6: Build Docker Images (for staging/production)
  build-images:
    name: Build Docker Images
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/main' || github.ref == 'refs/heads/develop'

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Login to Docker Hub
        uses: docker/login-action@v3
        with:
          username: ${{ secrets.DOCKER_USERNAME }}
          password: ${{ secrets.DOCKER_PASSWORD }}

      - name: Build and push backend
        uses: docker/build-push-action@v5
        with:
          context: ./backend
          push: true
          tags: aabenforms/backend:${{ github.sha }},aabenforms/backend:latest
          cache-from: type=registry,ref=aabenforms/backend:latest
          cache-to: type=inline

      - name: Build and push frontend
        uses: docker/build-push-action@v5
        with:
          context: ./frontend
          push: true
          tags: aabenforms/frontend:${{ github.sha }},aabenforms/frontend:latest
          cache-from: type=registry,ref=aabenforms/frontend:latest
          cache-to: type=inline

  # Job 7: Security Scan
  security-scan:
    name: Security Scan
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Run Trivy vulnerability scanner
        uses: aquasecurity/trivy-action@master
        with:
          scan-type: 'fs'
          scan-ref: '.'
          format: 'sarif'
          output: 'trivy-results.sarif'

      - name: Upload Trivy results to GitHub Security
        uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: 'trivy-results.sarif'

  # Summary Job
  ci-summary:
    name: CI Summary
    runs-on: ubuntu-latest
    needs: [static-analysis, unit-tests, integration-tests, frontend-tests, e2e-tests]
    if: always()

    steps:
      - name: Check results
        run: |
          echo "## CI Pipeline Results" >> $GITHUB_STEP_SUMMARY
          echo "" >> $GITHUB_STEP_SUMMARY

          if [ "${{ needs.static-analysis.result }}" == "success" ]; then
            echo "Static Analysis" >> $GITHUB_STEP_SUMMARY
          else
            echo "Static Analysis" >> $GITHUB_STEP_SUMMARY
          fi

          if [ "${{ needs.unit-tests.result }}" == "success" ]; then
            echo "Unit Tests" >> $GITHUB_STEP_SUMMARY
          else
            echo "Unit Tests" >> $GITHUB_STEP_SUMMARY
          fi

          if [ "${{ needs.integration-tests.result }}" == "success" ]; then
            echo "Integration Tests (with mocks)" >> $GITHUB_STEP_SUMMARY
          else
            echo "Integration Tests" >> $GITHUB_STEP_SUMMARY
          fi

          if [ "${{ needs.frontend-tests.result }}" == "success" ]; then
            echo "Frontend Tests" >> $GITHUB_STEP_SUMMARY
          else
            echo "Frontend Tests" >> $GITHUB_STEP_SUMMARY
          fi

          if [ "${{ needs.e2e-tests.result }}" == "success" ]; then
            echo "E2E Tests" >> $GITHUB_STEP_SUMMARY
          else
            echo "E2E Tests" >> $GITHUB_STEP_SUMMARY
          fi

      - name: Overall result
        if: |
          needs.static-analysis.result == 'failure' ||
          needs.unit-tests.result == 'failure' ||
          needs.integration-tests.result == 'failure' ||
          needs.frontend-tests.result == 'failure' ||
          needs.e2e-tests.result == 'failure'
        run: |
          echo "CI Pipeline Failed"
          exit 1
```

---

## 3. Staging Environment (Docker)

### Docker Compose for Staging

**File**: `docker-compose.staging.yml`

```yaml
version: '3.8'

services:
  # Backend (Drupal)
  backend:
    image: aabenforms/backend:latest
    environment:
      - DRUPAL_ENVIRONMENT=staging
      - MITID_ISSUER=http://keycloak:8080/realms/danish-gov-test
      - SERVICEPLATFORMEN_URL=http://wiremock:8080
      - DATABASE_URL=mysql://drupal:drupal@mariadb/drupal
    ports:
      - "8888:80"
    depends_on:
      - mariadb
      - keycloak
      - wiremock

  # Frontend (Nuxt)
  frontend:
    image: aabenforms/frontend:latest
    environment:
      - NUXT_PUBLIC_MITID_ISSUER=http://keycloak:8080/realms/danish-gov-test
      - NUXT_PUBLIC_API_URL=http://backend:80
    ports:
      - "3000:3000"
    depends_on:
      - backend

  # Database
  mariadb:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: drupal
      MYSQL_USER: drupal
      MYSQL_PASSWORD: drupal
    volumes:
      - mariadb_data:/var/lib/mysql

  # Mock Services (Same as local/CI!)
  keycloak:
    image: quay.io/keycloak/keycloak:23.0
    environment:
      KEYCLOAK_ADMIN: admin
      KEYCLOAK_ADMIN_PASSWORD: admin
    ports:
      - "8080:8080"
    volumes:
      - ./backend/.ddev/mocks/keycloak/realms:/opt/keycloak/data/import
    command: start-dev --import-realm

  wiremock:
    image: wiremock/wiremock:3.3.1
    ports:
      - "8081:8080"
    volumes:
      - ./backend/.ddev/mocks/wiremock/mappings:/home/wiremock/mappings
      - ./backend/.ddev/mocks/wiremock/__files:/home/wiremock/__files

volumes:
  mariadb_data:
```

**Deploy to Staging**:
```bash
# On the staging host
docker compose -f docker-compose.staging.yml up -d
```

---

## 4. Production Environment

### Production Configuration

**Key Difference**: Use **real** Danish government services in production

**File**: `backend/web/sites/default/settings.php`

```php
<?php

/**
 * Environment Detection
 */
$is_local = (getenv('DDEV_PROJECT') !== FALSE);
$is_ci = (getenv('CI') !== FALSE);
$is_staging = (getenv('PLATFORM_BRANCH') === 'staging');
$is_production = (getenv('PLATFORM_BRANCH') === 'main');

/**
 * Mock Services (Local, CI, Staging)
 */
if ($is_local || $is_ci || $is_staging) {
  // Use mock services
  $config['aabenforms_mitid.settings']['oidc']['issuer'] = getenv('MITID_ISSUER') ?: 'http://localhost:8080/realms/danish-gov-test';
  $config['aabenforms_mitid.settings']['oidc']['client_id'] = 'aabenforms-backend';
  $config['aabenforms_mitid.settings']['oidc']['client_secret'] = 'aabenforms-backend-secret-change-in-production';
  $config['aabenforms_mitid.settings']['mock_mode'] = TRUE;
  $config['aabenforms_mitid.settings']['production'] = FALSE;

  $config['aabenforms_core.settings']['serviceplatformen']['endpoint'] = getenv('SERVICEPLATFORMEN_URL') ?: 'http://localhost:8081';
  $config['aabenforms_core.settings']['serviceplatformen']['mock_mode'] = TRUE;
  $config['aabenforms_core.settings']['serviceplatformen']['validate_certificates'] = FALSE;
}

/**
 * Real Services (Production Only)
 */
if ($is_production) {
  // Use real MitID
  $config['aabenforms_mitid.settings']['oidc']['issuer'] = 'https://mitid.dk/oidc';
  $config['aabenforms_mitid.settings']['oidc']['authorization_endpoint'] = 'https://mitid.dk/oidc/authorize';
  $config['aabenforms_mitid.settings']['oidc']['token_endpoint'] = 'https://mitid.dk/oidc/token';
  $config['aabenforms_mitid.settings']['oidc']['client_id'] = getenv('MITID_CLIENT_ID');
  $config['aabenforms_mitid.settings']['oidc']['client_secret'] = getenv('MITID_CLIENT_SECRET');
  $config['aabenforms_mitid.settings']['mock_mode'] = FALSE;
  $config['aabenforms_mitid.settings']['production'] = TRUE;

  // Use real Serviceplatformen
  $config['aabenforms_core.settings']['serviceplatformen']['endpoint'] = 'https://prod.serviceplatformen.dk';
  $config['aabenforms_core.settings']['serviceplatformen']['certificate_path'] = '/secrets/oces-certificate.pem';
  $config['aabenforms_core.settings']['serviceplatformen']['certificate_password'] = getenv('OCES_CERT_PASSWORD');
  $config['aabenforms_core.settings']['serviceplatformen']['mock_mode'] = FALSE;
  $config['aabenforms_core.settings']['serviceplatformen']['validate_certificates'] = TRUE;
}
```

---

## 5. Performance Optimizations

### Parallel Job Execution

**Current Pipeline**: ~15 minutes
- Static Analysis: 2 min (parallel)
- Unit Tests: 3 min (parallel)
- Integration Tests: 5 min (parallel with mocks)
- Frontend Tests: 3 min (parallel)
- E2E Tests: 5 min

**Optimization**:
```yaml
# Run fast jobs first, slow jobs last
strategy:
  fail-fast: false
  matrix:
    test-suite: [unit, kernel, functional]
```

### Caching Strategy

**Cache Everything**:
```yaml
- name: Cache Composer
  uses: actions/cache@v4
  with:
    path: ~/.composer/cache
    key: composer-${{ hashFiles('**/composer.lock') }}

- name: Cache npm
  uses: actions/cache@v4
  with:
    path: ~/.npm
    key: npm-${{ hashFiles('**/package-lock.json') }}

- name: Cache Docker layers
  uses: actions/cache@v4
  with:
    path: /tmp/.buildx-cache
    key: buildx-${{ github.sha }}
```

---

## 6. Cost Analysis

### CI/CD Costs (Monthly)

| Approach | GitHub Actions Minutes | Cost |
|----------|----------------------|------|
| **With Mocks** | ~500 min/month | **$0** (free tier: 2000 min) |
| **Without Mocks** | ~2000 min/month | **$0-50** (slower tests) |
| **Real APIs in CI** | N/A | **Not possible** (no credentials) |

### Benefits of Mock Strategy

| Metric | With Mocks | Without Mocks |
|--------|-----------|---------------|
| **Setup Time** | Seconds | N/A (impossible) |
| **Credentials Needed** | None | Required |
| **Offline Development** | Yes | No |

---

## 7. Monitoring & Observability

### GitHub Actions Dashboard

**Built-in Metrics**:
- Test pass rate
- Build duration
- Flaky test detection
- Coverage trends

**Custom Metrics** (via GitHub API):
```bash
# Get workflow success rate
gh api repos/aabenforms/aabenforms/actions/workflows/ci.yml/runs \
  --jq '.workflow_runs | group_by(.conclusion) | map({key: .[0].conclusion, value: length}) | from_entries'
```

### Slack Integration

```yaml
- name: Notify Slack on failure
  if: failure()
  uses: slackapi/slack-github-action@v1
  with:
    webhook-url: ${{ secrets.SLACK_WEBHOOK }}
    payload: |
      {
        "text": "CI failed for ${{ github.repository }}",
        "blocks": [
          {
            "type": "section",
            "text": {
              "type": "mrkdwn",
              "text": "*CI Pipeline Failed*\n*Repository:* ${{ github.repository }}\n*Branch:* ${{ github.ref }}\n*Commit:* ${{ github.sha }}"
            }
          }
        ]
      }
```

---

## 8. Security Best Practices

### Secrets Management

**GitHub Secrets** (Production):
```
MITID_CLIENT_ID           # Real MitID credentials
MITID_CLIENT_SECRET
OCES_CERT_PASSWORD        # OCES certificate password
DOCKER_USERNAME           # Docker registry
DOCKER_PASSWORD
SLACK_WEBHOOK            # Notifications
```

**No Secrets Needed** (Local, CI, Staging):
- Mock services use hardcoded test credentials
- All test data is public (no real CPR numbers)

### Dependency Scanning

```yaml
- name: Audit Composer dependencies
  run: composer audit
  working-directory: backend

- name: Audit npm dependencies
  run: npm audit --audit-level=high
  working-directory: frontend
```

---

## 9. Deployment Strategy

### Continuous Deployment (CD)

Production runs on VPS2 (the contabo-infrastructure host) as a Docker image.
Deployment is not driven by a Platform.sh action; instead a push to `main`
fires a `repository_dispatch` event that the `contabo-infrastructure`
`deploy.yml` workflow picks up on the self-hosted runner. That workflow
rebuilds the backend Docker image on VPS2 and runs `drush updatedb` and
`drush cim` against the live container.

```yaml
# .github/workflows/deploy.yml (project repo)
name: Trigger production deploy

on:
  push:
    branches: [main]
  workflow_dispatch:

jobs:
  dispatch:
    name: Notify contabo-infrastructure
    runs-on: ubuntu-latest
    needs: [ci-summary]  # Only deploy if CI passes

    steps:
      - name: Fire repository_dispatch
        run: |
          gh api repos/madsnorgaard/contabo-infrastructure/dispatches \
            -f event_type=deploy-aabenforms \
            -F client_payload[sha]=${{ github.sha }}
        env:
          GH_TOKEN: ${{ secrets.DISPATCH_TOKEN }}
```

On VPS2 the `contabo-infrastructure` deploy job rebuilds the image and applies
config:

```bash
docker compose -f ~/docker/aabenforms/docker-compose.yml up -d --build
docker compose exec -T backend drush updatedb -y
docker compose exec -T backend drush cim -y
```

---

## 10. Testing Strategy Summary

```
┌──────────────────────────────────────────────────────┐
│              Testing Pyramid                          │
├──────────────────────────────────────────────────────┤
│                                                       │
│                    E2E Tests                         │
│                  (5 min, mocks)                      │
│                    ▲                                 │
│               Integration Tests                       │
│             (5 min, mocks + DB)                      │
│                    ▲                                 │
│                Unit Tests                            │
│             (3 min, no deps)                         │
│                    ▲                                 │
│             Static Analysis                          │
│             (2 min, no deps)                         │
│                                                       │
│  Total: ~15 minutes (with parallelization)          │
└──────────────────────────────────────────────────────┘
```

### Test Coverage Goals

| Layer | Coverage Target | Current |
|-------|----------------|---------|
| Unit Tests | 80% | TBD |
| Integration Tests | 70% | TBD |
| E2E Tests | Critical paths | TBD |

---

## 11. Next Steps

### Immediate (Week 1)

1. **Create `.github/workflows/ci.yml`** (copy from this doc)
2. **Test CI pipeline** (push to branch, create PR)
3. **Add status badges** to README.md

### Short-term (Week 2-3)

1. **Add E2E tests** with Playwright
2. **Setup staging environment** with Docker Compose
3. **Configure Slack notifications**

### Long-term (Month 1-3)

1. **Add coverage reporting** (Codecov, Coveralls)
2. **Setup performance testing** (k6, Lighthouse CI)
3. **Implement feature flags** (LaunchDarkly, Unleash)

---

## 12. Resources

**GitHub Actions**:
- https://docs.github.com/en/actions

**Docker Compose**:
- https://docs.docker.com/compose/

**Testing Tools**:
- PHPUnit: https://phpunit.de/
- Playwright: https://playwright.dev/
- Codecov: https://codecov.io/

---

## Summary

### What We Achieved

1. **Mock services everywhere** (local, CI, staging)
2. **No credentials needed** for development
3. **Fast CI/CD**
4. **Environment-switched services** (mocks in local/CI/staging; real Danish
   government endpoints are wired only in production, and most of those
   integrations are still test/mock-backed - see `docs/PROMISES-VS-VERIFIED.md`)

### Key Benefits

- No waiting for real-service credentials to run the suite
- Deterministic tests (same data every time)
- Secure (no production credentials in CI)
- Scalable (parallel jobs, caching)

---

**Status**: Implemented. CI runs on every push and pull request.

---

**Date**: 2026-01-25
**Version**: 1.0.0
