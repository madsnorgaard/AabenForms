# ÅbenForms Production Readiness Checklist

> **Superseded for go-live decisions** by [runbooks/PILOT_GO_LIVE.md](runbooks/PILOT_GO_LIVE.md), the short and current list. This document is kept as background and is partly out of date.

**Version**: 1.0
**Last Updated**: February 2026
**Purpose**: Comprehensive pre-launch checklist ensuring production readiness

Use this checklist before deploying ÅbenForms to production. Each section must be completed and verified by the appropriate team member.

---

## Table of Contents

1. [Security Checklist](#security-checklist)
2. [Performance Checklist](#performance-checklist)
3. [GDPR Compliance Checklist](#gdpr-compliance-checklist)
4. [Accessibility Checklist](#accessibility-checklist)
5. [Browser Compatibility](#browser-compatibility)
6. [Load Testing](#load-testing)
7. [Disaster Recovery](#disaster-recovery)
8. [Incident Response](#incident-response)
9. [Final Sign-Off](#final-sign-off)

---

## Security Checklist

### HTTPS/SSL Configuration

- [ ] **SSL certificate installed and valid**
  - Certificate type: [ ] Let's Encrypt [ ] Commercial
  - Expiration date: ___________
  - Wildcard support: [ ] Yes [ ] No
  - Tested on: https://www.ssllabs.com/ssltest/

- [ ] **TLS configuration hardened**
  - Minimum TLS version: TLS 1.2
  - TLS 1.3 enabled: [ ] Yes
  - Weak ciphers disabled: [ ] Yes
  - Forward secrecy enabled: [ ] Yes

- [ ] **HTTP to HTTPS redirect configured**
  - Test: `curl -I http://aabenforms.dk` returns 301
  - HSTS header enabled: [ ] Yes
  - HSTS max-age: 31536000 seconds (1 year)
  - includeSubDomains: [ ] Yes

### CORS Configuration

- [ ] **CORS headers properly configured**
  ```bash
  # Test CORS
  curl -H "Origin: https://aabenforms.dk" \
    -H "Access-Control-Request-Method: POST" \
    -H "Access-Control-Request-Headers: Content-Type" \
    -X OPTIONS https://api.aabenforms.dk/jsonapi --verbose
  ```

- [ ] **Allowed origins restricted**
  - Production frontend: `https://aabenforms.dk`
  - API subdomain: `https://api.aabenforms.dk`
  - NO wildcards (`*`) allowed in production

- [ ] **Allowed methods limited**
  - GET: [ ] Yes
  - POST: [ ] Yes
  - PATCH: [ ] Yes
  - DELETE: [ ] Yes (only for authorized endpoints)
  - OPTIONS: [ ] Yes
  - PUT: [ ] No (unless specifically needed)

### Security Headers

- [ ] **Content Security Policy (CSP) configured**
  ```nginx
  add_header Content-Security-Policy "
    default-src 'self';
    script-src 'self' 'unsafe-inline' 'unsafe-eval' https://mitid.dk;
    style-src 'self' 'unsafe-inline';
    img-src 'self' data: https:;
    font-src 'self' data:;
    connect-src 'self' https://api.aabenforms.dk https://service.serviceplatformen.dk;
    frame-ancestors 'none';
    base-uri 'self';
    form-action 'self' https://mitid.dk;
  " always;
  ```

- [ ] **X-Frame-Options set**
  ```nginx
  add_header X-Frame-Options "SAMEORIGIN" always;
  ```

- [ ] **X-Content-Type-Options set**
  ```nginx
  add_header X-Content-Type-Options "nosniff" always;
  ```

- [ ] **X-XSS-Protection set**
  ```nginx
  add_header X-XSS-Protection "1; mode=block" always;
  ```

- [ ] **Referrer-Policy configured**
  ```nginx
  add_header Referrer-Policy "strict-origin-when-cross-origin" always;
  ```

- [ ] **Permissions-Policy configured**
  ```nginx
  add_header Permissions-Policy "
    camera=(),
    microphone=(),
    geolocation=(self),
    payment=(self)
  " always;
  ```

### Drupal Security

- [ ] **Drupal core up to date**
  - Current version: ___________
  - Latest security release: ___________
  - Security updates applied: [ ] Yes

- [ ] **Contributed modules up to date**
  ```bash
  drush pm:security
  ```
  - No security vulnerabilities: [ ] Confirmed
  - Last checked: ___________

- [ ] **Admin account secured**
  - Username is NOT "admin": [ ] Yes
  - Strong password (20+ characters): [ ] Yes
  - Two-factor authentication enabled: [ ] Yes
  - Account email is monitored: [ ] Yes

- [ ] **User permissions reviewed**
  ```bash
  drush role:list --format=table
  ```
  - Anonymous users have minimal permissions: [ ] Yes
  - Authenticated users limited to necessary permissions: [ ] Yes
  - Administrator role restricted to trusted users: [ ] Yes

- [ ] **File permissions correct**
  ```bash
  # Verify permissions
  ls -la web/sites/default/
  ```
  - `settings.php`: 444 (read-only)
  - `files/`: 755 (writable by web server)
  - `private/`: 700 (not web-accessible)

- [ ] **PHP configuration hardened**
  - `expose_php = Off`
  - `display_errors = Off`
  - `log_errors = On`
  - `allow_url_fopen = Off` (unless required)
  - `disable_functions` includes dangerous functions

- [ ] **Database credentials secured**
  - NOT in version control: [ ] Yes
  - Stored in `.env` file: [ ] Yes
  - `.env` file permissions: 600
  - Database user has minimal privileges: [ ] Yes
  - Database password is strong: [ ] Yes

### Encryption & Key Management

- [ ] **Encryption keys generated**
  - AES encryption key: [ ] Generated
  - Key storage location: `/var/www/aabenforms/backend/private/encryption.key`
  - Key file permissions: 600
  - Key backed up securely: [ ] Yes

- [ ] **CPR data encrypted**
  ```bash
  drush config:get encrypt.profile.cpr_encryption
  ```
  - Field-level encryption enabled: [ ] Yes
  - Encryption algorithm: AES-256-CBC
  - Test encryption/decryption: [ ] Passed

- [ ] **SSL certificates for Serviceplatformen**
  - Client certificate installed: [ ] Yes
  - Certificate password secured: [ ] Yes
  - Certificate expiration date: ___________
  - Renewal process documented: [ ] Yes

### Authentication & Authorization

- [ ] **MitID integration configured**
  - Production MitID credentials: [ ] Configured
  - Redirect URIs whitelisted: [ ] Yes
  - NSIS compliance verified: [ ] Yes
  - Test login flow: [ ] Passed

- [ ] **Session security**
  - Session lifetime: 3600 seconds (1 hour)
  - Session cookie secure flag: [ ] Yes
  - Session cookie httponly flag: [ ] Yes
  - Session cookie samesite: Strict

- [ ] **Failed login protection**
  - Flood control enabled: [ ] Yes
  - Max login attempts: 5 per hour
  - IP-based blocking: [ ] Yes
  - Account lockout after failed attempts: [ ] Yes

### Firewall & Access Control

- [ ] **Server firewall configured**
  ```bash
  sudo ufw status
  ```
  - Only ports 22, 80, 443 open: [ ] Yes
  - SSH restricted to specific IPs: [ ] Yes (optional)
  - Database port NOT exposed: [ ] Confirmed

- [ ] **Admin paths protected**
  - `/admin/*` requires authentication: [ ] Yes
  - `/user/*` requires CAPTCHA (optional): [ ] Yes
  - IP whitelist for admin (optional): [ ] No

- [ ] **Rate limiting configured**
  - API endpoints: 100 requests/minute
  - Login endpoint: 10 requests/minute
  - Form submissions: 20 requests/minute

### Security Auditing

- [ ] **Security scan performed**
  - Tool used: [ ] Acunetix [ ] OWASP ZAP [ ] Burp Suite
  - Scan date: ___________
  - Critical vulnerabilities: 0
  - High vulnerabilities: 0
  - Medium vulnerabilities: ___________
  - Remediation plan: [ ] Documented

- [ ] **Penetration testing completed**
  - Tester: ___________
  - Test date: ___________
  - Report reviewed: [ ] Yes
  - Vulnerabilities remediated: [ ] Yes

---

## Performance Checklist

### Caching Strategy

- [ ] **Drupal performance settings optimized**
  ```bash
  drush config:get system.performance
  ```
  - CSS aggregation: [ ] Enabled
  - JavaScript aggregation: [ ] Enabled
  - Page cache max age: 3600 seconds
  - Anonymous page cache: [ ] Enabled
  - Twig debugging: [ ] Disabled
  - Twig auto-reload: [ ] Disabled

- [ ] **Redis caching configured**
  ```bash
  redis-cli ping
  drush eval "var_dump(\Drupal::cache('default')->get('test'));"
  ```
  - Redis connected: [ ] Yes
  - Cache bins configured: [ ] Yes
  - Test cache write/read: [ ] Passed

- [ ] **OpCache enabled**
  ```bash
  php -i | grep opcache.enable
  ```
  - OpCache enabled: [ ] Yes
  - `opcache.memory_consumption`: 256M
  - `opcache.max_accelerated_files`: 20000
  - `opcache.validate_timestamps`: 0 (production)

- [ ] **APCu enabled (optional)**
  - APCu installed: [ ] Yes
  - Used for local caching: [ ] Yes

### Database Optimization

- [ ] **Database indexes verified**
  ```sql
  SHOW INDEX FROM webform_submission;
  ```
  - All foreign keys indexed: [ ] Yes
  - Frequently queried fields indexed: [ ] Yes

- [ ] **Database tables optimized**
  ```bash
  drush sqlq "OPTIMIZE TABLE cache_bootstrap, cache_config, cache_data;"
  ```
  - Tables optimized: [ ] Yes
  - Last optimization date: ___________

- [ ] **Slow query log enabled**
  ```sql
  SET GLOBAL slow_query_log = 'ON';
  SET GLOBAL long_query_time = 2;
  ```
  - Slow queries monitored: [ ] Yes
  - Queries > 2 seconds identified: [ ] Yes
  - Optimization applied: [ ] Yes

- [ ] **Database connection pooling**
  - Max connections: 100
  - Connection timeout: 30 seconds
  - Persistent connections: [ ] Enabled

### CDN Configuration

- [ ] **CDN enabled**
  - Provider: [ ] Cloudflare [ ] AWS CloudFront [ ] Other: ___________
  - DNS pointed to CDN: [ ] Yes
  - SSL certificate issued by CDN: [ ] Yes

- [ ] **Static assets cached**
  - CSS/JS cached: [ ] Yes (TTL: 1 year)
  - Images cached: [ ] Yes (TTL: 1 month)
  - Fonts cached: [ ] Yes (TTL: 1 year)

- [ ] **Cache purging configured**
  - Manual purge tested: [ ] Yes
  - Automated purge on deployment: [ ] Yes
  - Cache tags supported: [ ] Yes

### Image Optimization

- [ ] **Image styles configured**
  ```bash
  drush image:styles
  ```
  - Responsive image styles: [ ] Yes
  - WebP format enabled: [ ] Yes (if supported)
  - Lazy loading: [ ] Yes

- [ ] **File size limits**
  - Max upload size: 10MB
  - Allowed file types restricted: [ ] Yes
  - Virus scanning enabled (optional): [ ] No

### Compression

- [ ] **Gzip compression enabled**
  ```bash
  curl -H "Accept-Encoding: gzip" -I https://aabenforms.dk
  ```
  - Gzip enabled in Nginx: [ ] Yes
  - Compression level: 6

- [ ] **Brotli compression enabled**
  - Brotli supported: [ ] Yes
  - Fallback to Gzip: [ ] Yes

### Performance Testing

- [ ] **Page load time < 3 seconds**
  - Tool: [ ] GTmetrix [ ] Lighthouse [ ] WebPageTest
  - Homepage load time: ___________ seconds
  - API response time: ___________ milliseconds
  - Test date: ___________

- [ ] **Lighthouse score**
  ```bash
  lighthouse https://aabenforms.dk --view
  ```
  - Performance score: _____ / 100 (target: > 90)
  - Accessibility score: _____ / 100 (target: > 90)
  - Best Practices score: _____ / 100 (target: > 90)
  - SEO score: _____ / 100 (target: > 90)

- [ ] **Core Web Vitals**
  - Largest Contentful Paint (LCP): < 2.5s
  - First Input Delay (FID): < 100ms
  - Cumulative Layout Shift (CLS): < 0.1

### Server Resources

- [ ] **Server capacity adequate**
  - CPU usage under load: < 70%
  - Memory usage: < 80%
  - Disk I/O: < 80%
  - Network bandwidth: Adequate for expected traffic

- [ ] **Auto-scaling configured (if applicable)**
  - Min instances: 2
  - Max instances: 10
  - Scale-up threshold: CPU > 70%
  - Scale-down threshold: CPU < 30%

---

## GDPR Compliance Checklist

### Data Inventory

- [ ] **Personal data mapped**
  - Data types documented: [ ] Yes
  - Data sources identified: [ ] Yes
  - Data flows mapped: [ ] Yes
  - Data retention periods defined: [ ] Yes

- [ ] **Legal basis established**
  - Consent: [ ] Yes (for marketing, optional features)
  - Contract: [ ] Yes (for service delivery)
  - Legal obligation: [ ] Yes (for CPR lookups)
  - Legitimate interest: [ ] Documented

### Privacy Policy

- [ ] **Privacy policy created**
  - URL: https://aabenforms.dk/privatlivspolitik
  - Accessible from all pages: [ ] Yes
  - Last updated: ___________
  - Approved by legal counsel: [ ] Yes

- [ ] **Privacy policy content**
  - Data controller identified: [ ] Yes
  - Data protection officer (DPO) contact: [ ] Yes
  - Types of data collected: [ ] Documented
  - Purpose of processing: [ ] Documented
  - Legal basis: [ ] Documented
  - Data retention periods: [ ] Documented
  - Third-party processors: [ ] Documented
  - User rights explained: [ ] Yes
  - Cookie policy: [ ] Yes

### Consent Management

- [ ] **Cookie consent implemented**
  - Cookie banner displayed: [ ] Yes
  - Granular consent options: [ ] Yes
  - Consent recorded with timestamp: [ ] Yes
  - Withdrawal mechanism: [ ] Yes

- [ ] **Consent for data processing**
  - Explicit consent for CPR lookup: [ ] Yes
  - Consent for marketing: [ ] Yes (opt-in)
  - Consent can be withdrawn: [ ] Yes

### Data Subject Rights

- [ ] **Right to access implemented**
  - Users can download their data: [ ] Yes
  - Data export format: JSON
  - Response time: < 30 days

- [ ] **Right to rectification**
  - Users can edit their data: [ ] Yes
  - Admins can correct data: [ ] Yes

- [ ] **Right to erasure (right to be forgotten)**
  - Users can request deletion: [ ] Yes
  - Deletion workflow: [ ] Automated
  - Compliance with legal retention: [ ] Yes
  - Audit log of deletions: [ ] Yes

- [ ] **Right to data portability**
  - Data export in machine-readable format: [ ] Yes
  - Formats supported: JSON, CSV

- [ ] **Right to object**
  - Users can opt-out of processing: [ ] Yes
  - Opt-out honored immediately: [ ] Yes

### Data Protection Measures

- [ ] **CPR encryption**
  ```bash
  drush config:get encrypt.profile.cpr_encryption
  ```
  - CPR fields encrypted at rest: [ ] Yes
  - Encryption algorithm: AES-256
  - Decryption limited to authorized users: [ ] Yes

- [ ] **Access logging**
  - All CPR lookups logged: [ ] Yes
  - Access logs retained for: 5 years
  - Logs include: user, timestamp, purpose, IP

- [ ] **Data minimization**
  - Only necessary data collected: [ ] Yes
  - CPR requested only when required: [ ] Yes
  - Unnecessary fields removed from forms: [ ] Yes

### Data Retention

- [ ] **Retention policies defined**
  - Webform submissions: 5 years (configurable)
  - Audit logs: 5 years
  - User accounts: Until account deletion
  - Session data: 24 hours

- [ ] **Automated data deletion**
  - Cron job configured: [ ] Yes
  - Test deletion workflow: [ ] Passed
  - Deletion logs maintained: [ ] Yes

### Third-Party Processors

- [ ] **Data processing agreements (DPAs)**
  - Platform.sh: [ ] Signed
  - Email provider (SendGrid): [ ] Signed
  - Monitoring (New Relic): [ ] Signed
  - CDN (Cloudflare): [ ] Signed
  - KOMBIT Serviceplatformen: [ ] Covered by public sector agreement

- [ ] **Processor compliance verified**
  - GDPR compliance confirmed: [ ] Yes
  - EU data residency: [ ] Yes
  - Sub-processors documented: [ ] Yes

### Breach Notification

- [ ] **Breach detection procedures**
  - Monitoring in place: [ ] Yes
  - Alerting configured: [ ] Yes
  - Incident response plan: [ ] Documented

- [ ] **Breach notification plan**
  - DPO contact: ___________
  - Supervisory authority: Datatilsynet (Denmark)
  - Notification timeline: Within 72 hours
  - User notification procedure: [ ] Documented

### Data Protection Impact Assessment (DPIA)

- [ ] **DPIA conducted**
  - Assessment date: ___________
  - High-risk processing identified: [ ] Yes
  - Mitigations implemented: [ ] Yes
  - Approved by DPO: [ ] Yes

---

## Accessibility Checklist

### WCAG 2.1 Level AA Compliance

- [ ] **Perceivable**
  - [ ] **1.1 Text Alternatives**
    - All images have alt text: [ ] Yes
    - Decorative images have empty alt: [ ] Yes
    - Form inputs have labels: [ ] Yes

  - [ ] **1.2 Time-based Media**
    - Videos have captions: [ ] Yes (if applicable)
    - Audio descriptions provided: [ ] Yes (if applicable)

  - [ ] **1.3 Adaptable**
    - Semantic HTML used: [ ] Yes
    - Heading hierarchy correct: [ ] Yes
    - Form labels associated with inputs: [ ] Yes
    - Tables have proper markup: [ ] Yes

  - [ ] **1.4 Distinguishable**
    - Color contrast ratio ≥ 4.5:1: [ ] Yes
    - Text resizable to 200%: [ ] Yes
    - No information conveyed by color alone: [ ] Yes
    - Background audio can be turned off: [ ] Yes (if applicable)

- [ ] **Operable**
  - [ ] **2.1 Keyboard Accessible**
    - All functionality keyboard accessible: [ ] Yes
    - No keyboard traps: [ ] Yes
    - Keyboard shortcuts documented: [ ] Yes

  - [ ] **2.2 Enough Time**
    - Session timeout warning: [ ] Yes
    - User can extend timeout: [ ] Yes
    - Auto-updating content can be paused: [ ] Yes

  - [ ] **2.3 Seizures and Physical Reactions**
    - No content flashes more than 3 times/second: [ ] Yes

  - [ ] **2.4 Navigable**
    - Skip navigation link provided: [ ] Yes
    - Page titles descriptive: [ ] Yes
    - Focus order logical: [ ] Yes
    - Link purpose clear from context: [ ] Yes
    - Multiple ways to find pages: [ ] Yes
    - Headings and labels descriptive: [ ] Yes
    - Focus visible: [ ] Yes

- [ ] **Understandable**
  - [ ] **3.1 Readable**
    - Language of page specified: `<html lang="da">`
    - Language changes marked: [ ] Yes

  - [ ] **3.2 Predictable**
    - Navigation consistent across pages: [ ] Yes
    - Components consistent: [ ] Yes
    - No unexpected context changes: [ ] Yes

  - [ ] **3.3 Input Assistance**
    - Error messages clear and helpful: [ ] Yes
    - Form validation errors identified: [ ] Yes
    - Labels or instructions provided: [ ] Yes
    - Error prevention for legal/financial transactions: [ ] Yes

- [ ] **Robust**
  - [ ] **4.1 Compatible**
    - Valid HTML: [ ] Yes
    - ARIA attributes used correctly: [ ] Yes
    - Status messages announced: [ ] Yes

### Assistive Technology Testing

- [ ] **Screen reader testing**
  - NVDA (Windows): [ ] Tested
  - JAWS (Windows): [ ] Tested
  - VoiceOver (macOS): [ ] Tested
  - TalkBack (Android): [ ] Tested

- [ ] **Keyboard-only navigation**
  - All interactive elements reachable: [ ] Yes
  - Tab order logical: [ ] Yes
  - Focus indicators visible: [ ] Yes

### Accessibility Audit

- [ ] **Automated testing**
  - Tool: [ ] axe DevTools [ ] WAVE [ ] Lighthouse
  - Issues found: ___________
  - Issues resolved: [ ] Yes

- [ ] **Manual testing**
  - Tested by: ___________
  - Test date: ___________
  - Issues found: ___________
  - Issues resolved: [ ] Yes

### Accessibility Statement

- [ ] **Accessibility statement published**
  - URL: https://aabenforms.dk/tilgaengelighed
  - Conformance level: WCAG 2.1 Level AA
  - Known issues documented: [ ] Yes
  - Feedback mechanism provided: [ ] Yes

---

## Browser Compatibility

### Desktop Browsers

- [ ] **Google Chrome** (latest)
  - Version tested: ___________
  - All features working: [ ] Yes
  - Performance acceptable: [ ] Yes

- [ ] **Mozilla Firefox** (latest)
  - Version tested: ___________
  - All features working: [ ] Yes
  - Performance acceptable: [ ] Yes

- [ ] **Microsoft Edge** (latest)
  - Version tested: ___________
  - All features working: [ ] Yes
  - Performance acceptable: [ ] Yes

- [ ] **Safari** (latest)
  - Version tested: ___________
  - All features working: [ ] Yes
  - Performance acceptable: [ ] Yes

### Mobile Browsers

- [ ] **Chrome Mobile** (Android)
  - Device tested: ___________
  - All features working: [ ] Yes
  - Touch interactions working: [ ] Yes

- [ ] **Safari Mobile** (iOS)
  - Device tested: ___________
  - All features working: [ ] Yes
  - Touch interactions working: [ ] Yes

### Legacy Browser Support

- [ ] **IE11** (if required)
  - Polyfills included: [ ] Yes
  - Graceful degradation: [ ] Yes
  - Warning message for unsupported features: [ ] Yes

### Responsive Design

- [ ] **Mobile viewport**
  - Viewport meta tag: `<meta name="viewport" content="width=device-width, initial-scale=1">`
  - Mobile-friendly test passed: [ ] Yes
  - Touch targets ≥ 48x48 pixels: [ ] Yes

- [ ] **Tablet viewport**
  - Layout adapts correctly: [ ] Yes
  - All features accessible: [ ] Yes

- [ ] **Desktop viewport**
  - Layout optimized for large screens: [ ] Yes
  - No horizontal scrolling: [ ] Yes

---

## Load Testing

### Load Testing Tools

- [ ] **Load test performed**
  - Tool: [ ] Apache JMeter [ ] Gatling [ ] K6 [ ] Artillery
  - Test date: ___________
  - Test duration: ___________

### Test Scenarios

- [ ] **Baseline load test**
  - Concurrent users: 100
  - Duration: 30 minutes
  - Average response time: ___________ ms
  - 95th percentile: ___________ ms
  - Error rate: ___________% (target: < 1%)

- [ ] **Peak load test**
  - Concurrent users: 500
  - Duration: 15 minutes
  - Average response time: ___________ ms
  - 95th percentile: ___________ ms
  - Error rate: ___________% (target: < 5%)

- [ ] **Stress test**
  - Concurrent users: 1000+
  - Duration: 10 minutes
  - Breaking point: ___________ users
  - Recovery time: ___________ minutes

- [ ] **Soak test**
  - Concurrent users: 200
  - Duration: 4 hours
  - Memory leaks detected: [ ] No
  - Performance degradation: [ ] No

### Critical User Journeys

- [ ] **Form submission flow**
  - TPS (transactions per second): ___________
  - Success rate: ___________% (target: > 99%)
  - Average duration: ___________ seconds

- [ ] **MitID authentication**
  - Concurrent logins: 50
  - Success rate: ___________% (target: > 95%)
  - Average login time: ___________ seconds

- [ ] **API requests**
  - Requests per second: ___________
  - Average response time: ___________ ms (target: < 500ms)
  - Error rate: ___________% (target: < 1%)

### Results Analysis

- [ ] **Bottlenecks identified**
  - Database queries: [ ] Optimized
  - External API calls: [ ] Optimized
  - Memory usage: [ ] Within limits
  - CPU usage: [ ] Within limits

- [ ] **Recommendations implemented**
  - Horizontal scaling: [ ] Yes
  - Caching improvements: [ ] Yes
  - Code optimizations: [ ] Yes

---

## Disaster Recovery

### Backup Strategy

- [ ] **Automated backups configured**
  - Frequency: Daily
  - Retention: 30 days
  - Backup types: Database, Files, Code
  - Backup location: [ ] S3 [ ] Backblaze [ ] Platform.sh
  - Offsite backups: [ ] Yes

- [ ] **Backup restoration tested**
  - Last test date: ___________
  - Database restored successfully: [ ] Yes
  - Files restored successfully: [ ] Yes
  - RTO (Recovery Time Objective): ___________ hours
  - RPO (Recovery Point Objective): ___________ hours

### High Availability

- [ ] **Load balancing configured**
  - Load balancer type: [ ] ALB [ ] Nginx [ ] HAProxy [ ] Cloudflare
  - Health checks enabled: [ ] Yes
  - Failover tested: [ ] Yes

- [ ] **Database replication**
  - Master-slave replication: [ ] Yes
  - Automatic failover: [ ] Yes
  - Replication lag: < 1 second

- [ ] **Multi-region deployment (optional)**
  - Primary region: ___________
  - Secondary region: ___________
  - Geo-routing configured: [ ] Yes

### Disaster Recovery Plan

- [ ] **DR plan documented**
  - Document location: `/docs/DISASTER_RECOVERY_PLAN.md`
  - Last updated: ___________
  - Reviewed by: ___________

- [ ] **DR plan tested**
  - Test date: ___________
  - Test scenario: ___________
  - Recovery successful: [ ] Yes
  - Lessons learned documented: [ ] Yes

### Business Continuity

- [ ] **Critical dependencies identified**
  - External services: MitID, Serviceplatformen, Email
  - Fallback procedures: [ ] Documented
  - SLA requirements: [ ] Defined

- [ ] **Communication plan**
  - Status page: https://status.aabenforms.dk
  - Email notifications: [ ] Configured
  - Social media: [ ] Configured
  - Stakeholder contacts: [ ] Documented

---

## Incident Response

### Incident Response Plan

- [ ] **IRP documented**
  - Document location: `/docs/INCIDENT_RESPONSE_PLAN.md`
  - Last updated: ___________
  - Approved by: ___________

### Incident Classification

- [ ] **Severity levels defined**
  - P1 (Critical): Complete outage
  - P2 (High): Major functionality broken
  - P3 (Medium): Minor functionality impaired
  - P4 (Low): Cosmetic issues

### Response Team

- [ ] **Incident response team identified**
  - Incident Commander: ___________
  - Technical Lead: ___________
  - Communications Lead: ___________
  - On-call schedule: [ ] Defined

### Monitoring & Alerting

- [ ] **Monitoring configured**
  - Uptime monitoring: [ ] UptimeRobot [ ] Pingdom [ ] StatusCake
  - Check interval: 1 minute
  - Alert threshold: 2 failed checks

- [ ] **Alert channels configured**
  - Email: [ ] Yes
  - SMS: [ ] Yes
  - Slack: [ ] Yes
  - PagerDuty: [ ] No

- [ ] **Monitored metrics**
  - Site availability: [ ] Yes
  - Response time: [ ] Yes
  - Error rate: [ ] Yes
  - Database connectivity: [ ] Yes
  - Disk space: [ ] Yes
  - Memory usage: [ ] Yes
  - CPU usage: [ ] Yes

### Incident Logging

- [ ] **Incident tracking system**
  - Tool: [ ] Jira [ ] GitHub Issues [ ] PagerDuty
  - All incidents logged: [ ] Yes
  - Post-incident reviews conducted: [ ] Yes

### Post-Incident Review

- [ ] **PIR process defined**
  - Review timeline: Within 48 hours
  - Attendees: Incident response team
  - Outputs: Root cause analysis, action items
  - Follow-up: [ ] Documented

---

## Final Sign-Off

### Pre-Launch Review

- [ ] **All checklist items completed**
  - Security: [ ] 100%
  - Performance: [ ] 100%
  - GDPR: [ ] 100%
  - Accessibility: [ ] 100%
  - Browser compatibility: [ ] 100%
  - Load testing: [ ] 100%
  - Disaster recovery: [ ] 100%
  - Incident response: [ ] 100%

### Stakeholder Approval

- [ ] **Technical Lead approval**
  - Name: ___________
  - Signature: ___________
  - Date: ___________

- [ ] **Security Officer approval**
  - Name: ___________
  - Signature: ___________
  - Date: ___________

- [ ] **Data Protection Officer approval**
  - Name: ___________
  - Signature: ___________
  - Date: ___________

- [ ] **Product Owner approval**
  - Name: ___________
  - Signature: ___________
  - Date: ___________

### Go-Live Readiness

- [ ] **Launch date confirmed**
  - Target date: ___________
  - Maintenance window scheduled: [ ] Yes
  - Stakeholders notified: [ ] Yes

- [ ] **Rollback plan prepared**
  - Documented: [ ] Yes
  - Tested: [ ] Yes

- [ ] **Support plan activated**
  - On-call team ready: [ ] Yes
  - Escalation paths defined: [ ] Yes

---

## Notes

Use this section to document any exceptions, deviations, or additional context:

___________________________________________________________________________
___________________________________________________________________________
___________________________________________________________________________
___________________________________________________________________________
___________________________________________________________________________

---

**Document Version**: 1.0
**Last Updated**: February 2026
**Maintained By**: ÅbenForms QA Team
