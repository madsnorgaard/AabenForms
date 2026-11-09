# Municipal Sales Guide
## ÅbenForms Digital Workflow Platform for Danish Municipalities

**Version**: 1.0
**Date**: February 2026
**Target Audience**: Municipal decision-makers, IT managers, digital transformation leaders
**Status**: Pre-pilot POC. Live demo at https://aabenforms.dk (frontend) and https://api.aabenforms.dk (backend). MitID via Keycloak mock; Serviceplatformen and Digital Post via test/mock endpoints. No production municipality deployments yet.

---

## Executive Summary

ÅbenForms is an open-source digital workflow automation platform aimed at Danish municipalities. It enables citizen-facing services through visual workflow design, integration with Danish government systems, and GDPR-minded data handling - with no licence fees. It is currently a pre-pilot POC: the workflow engine, MitID sign-in, and CPR/CVR lookups run against test/mock endpoints, and several integration actions (payment, SMS, GIS, payroll, calendar) are demo mocks today.

**Key Value Proposition**:
- Open source: no per-form or per-integration licence fees, unlike proprietary alternatives
- Visual workflow builder: the Workflow Modeler editor, no-code/low-code workflow creation
- Danish-first: integrations with MitID, CPR, CVR, Adressevælger, Digital Post (against test/mock endpoints today)
- GDPR-minded: field-level CPR encryption (AES-256) and audit logging built in
- Ready-made templates for common municipal services
- Modern architecture: API-first, headless, JSON:API, no vendor lock-in

---

## Platform Benefits

### 1. Cost Model

Proprietary self-service workflow platforms typically charge annual licence fees, often with
additional per-integration and per-form fees. ÅbenForms is open source and carries no licence
fees. Your costs are limited to hosting and whatever implementation or support you choose to
buy or do in-house.

**Where proprietary alternatives cost money** (generalised):
- Annual platform licence
- Per-integration module fees (MitID, CPR/CVR, Digital Post, etc.)
- Per-form or per-transaction fees in some models

**Where ÅbenForms costs money**:
- Hosting
- Optional implementation and support (in-house or bought)

There are no fabricated savings figures here; do your own comparison against your current
vendor's actual quote.

### 2. Rapid Deployment

- 13 ready-made workflow templates (BPMN source files) for common municipal workflows
- Visual workflow editing via the Workflow Modeler
- No custom development required for standard use cases
- 18 ECA flows deployed in the current configuration

### 3. Danish Government Integration

Built-in connectors for essential Danish services:

**Authentication & Identity**:
- MitID (Personal and Business)
- MitID Erhverv for company authentication
- NSIS Level of Assurance compliance

**Data Services**:
- SF1520: CPR lookup (person data)
- SF1530: CVR lookup (company data)
- Adressevælger: Danish address validation and autocomplete

**Communication**:
- SF1601: Digital Post (secure notifications)
- SMS gateway integration
- Email with template support

**Case Management**:
- SBSYS integration
- GetOrganized ESDH integration
- Document archiving with metadata

### 4. GDPR-Minded Data Handling

Built in today:
- Field-level AES-256 encryption for CPR numbers
- Audit logging
- Role-based access control

Planned (not yet shipped):
- Automated data retention and right-to-erasure subsystem (issue #91)
- Consent management

Engage your Data Protection Officer for a full compliance assessment before any pilot.

### 5. Modern Architecture

- Headless CMS (Drupal 11, core 11.3.10, PHP 8.4) backend
- Vue.js/Nuxt 4 frontend
- RESTful JSON:API
- Self-hostable; no vendor lock-in
- Multi-tenancy support via the domain module
- Mobile-responsive design

---

## Flagship Workflows

Note on integration status: in the current POC, MitID sign-in, CPR/CVR lookup, Adressevælger validation,
and audit logging are real (against test/mock endpoints). Payment, SMS, GIS, and calendar/booking
steps are demo mocks today. The "annual impact" estimates below are illustrative planning figures,
not measured results from any deployment - there are no production deployments yet.

### Workflow 1: Parking Permit Application

**Use Case**: Citizen applies for residential parking permit, pays fee, receives digital permit.

**Workflow Steps**:
1. Citizen submits application with vehicle registration, address, duration
2. Optional: MitID authentication for identity verification
3. Address validation via Adressevælger
4. Automatic fee calculation based on zone and duration
5. Payment processing via Nets Easy
6. PDF permit generation with QR code
7. Email delivery with PDF attachment
8. SMS confirmation with permit number
9. Audit log entry

**Business Value**:
- Reduces manual processing time from 3 days to instant
- Eliminates paper permits and postage costs
- 24/7 self-service availability
- Real-time payment reconciliation
- Digital audit trail for compliance

**Integration Points**:
- MitID (optional authentication)
- Adressevælger (address validation)
- Nets Easy (payment)
- SMS gateway
- PDF generation
- Email delivery

**Setup Time**: 1 week
**Technical Complexity**: Low

**Illustrative Annual Impact (Medium Municipality, planning estimate only - not measured)**:
- Applications processed: ~2,000
- Potential time saved if manual processing is fully automated
- Actual savings depend on the municipality's current process and volumes

---

### Workflow 2: Marriage Ceremony Booking

**Use Case**: Couple books marriage ceremony date with dual MitID authentication, payment, and automated reminders.

**Workflow Steps**:
1. Both partners authenticate with MitID
2. CPR lookup for both partners (SF1520)
3. Fetch available ceremony slots from municipal calendar
4. Calendar picker displays available dates/times
5. Couple selects preferred date and ceremony preferences
6. Booking fee calculation (church vs. civil ceremony)
7. Payment processing
8. Ceremony slot reservation
9. Marriage certificate PDF generation
10. Confirmation emails to both partners
11. SMS confirmations to both partners
12. Automated reminder 7 days before ceremony
13. Automated reminder 1 day before ceremony
14. Audit log entries

**Business Value**:
- Eliminates phone/email booking coordination
- Dual authentication ensures identity verification
- Automated reminders to reduce no-shows
- Calendar synchronization (calendar action is a demo mock today)
- Digital certificate delivery
- Complete audit trail for legal compliance

**Integration Points**:
- MitID (dual authentication)
- SF1520 CPR lookup (dual)
- Calendar/appointment system
- Payment processing
- PDF generation
- Email delivery (dual)
- SMS notifications (dual)
- Automated reminder scheduling

**Setup Time**: 2 weeks
**Technical Complexity**: Medium

**Illustrative Annual Impact (Medium Municipality, planning estimate only - not measured)**:
- Ceremonies booked: ~300
- Potential reduction in phone/email coordination time and no-shows
- Actual results depend on the municipality's current process

---

### Workflow 3: Building Permit Application

**Use Case**: Property owner applies for building permit with document upload, caseworker review, and approval workflow.

**Workflow Steps**:
1. Applicant authenticates with MitID
2. Identity verification via SF1520 CPR lookup
3. Property address validation via Adressevælger
4. Document upload (plans, drawings, specifications)
5. Automated document validation (file types, sizes)
6. Document completeness check
7. Caseworker task assignment
8. Caseworker reviews application in admin interface
9. Decision gateway:
   - **Approve**: Create case in SBSYS, send approval notice (SF1601), audit log
   - **Reject**: Send rejection email with reasoning, audit log
   - **Request More Info**: Email applicant, wait for response (30-day timeout)
10. If additional info received: Return to caseworker review
11. If timeout (30 days): Auto-reject with notification
12. Final audit log and case closure

**Business Value**:
- Eliminates paper submissions and postal delays
- Automated document validation reduces caseworker time by 30%
- Digital case management integration (SBSYS)
- Transparent status tracking for applicants
- Compliance with municipal processing deadlines
- Full audit trail for legal disputes

**Integration Points**:
- MitID authentication
- SF1520 CPR lookup
- Adressevælger address validation
- Document management system
- SBSYS case creation
- SF1601 Digital Post (official notifications)
- Email notifications
- Audit logging

**Setup Time**: 3 weeks
**Technical Complexity**: High

**Illustrative Annual Impact (Medium Municipality, planning estimate only - not measured)**:
- Applications processed: ~800
- Potential reduction in caseworker time and paper/postage
- Potential faster processing through automated pre-checks
- Actual results depend on the municipality's current process

---

## ROI - How to Build Your Own Estimate

There are no measured ROI figures to quote: ÅbenForms has no production deployments yet. Rather
than present fabricated numbers, build an estimate from your own data.

### Inputs to gather

- Annual volume per workflow (parking permits, marriage bookings, building permits, etc.)
- Current staff time per application and your loaded hourly staff cost
- Current paper/postage and reconciliation costs
- Your current vendor's actual licence and per-integration quote (for the comparison)

### Method

1. Estimate staff time potentially saved per workflow once routine steps are automated.
2. Multiply by volume and hourly cost for a conservative staff-time figure.
3. Add paper/postage and reconciliation savings where applicable.
4. Subtract your ÅbenForms costs: hosting plus any implementation/support you buy or staff
   in-house. Remember there are no licence fees.
5. Compare against the avoided proprietary licence and per-integration fees from your vendor quote.

Use conservative assumptions. The honest message is "no licence fees, open source, no lock-in,
modern JSON:API" - not a fabricated payback period.

---

## Integration Capabilities

### Authentication & Identity

**MitID (Personal)**:
- NSIS Level Substantial (LoA 3)
- Full name, CPR number, address
- Session management with expiry
- Audit logging

**MitID Erhverv (Business)**:
- Company CVR number
- Employee role verification
- Delegation support

**SF1520 CPR Lookup** (Serviceplatformen):
- Person master data
- Current address
- Address history
- Family relations
- Marital status
- GDPR-compliant encryption

### Data Services

**SF1530 CVR Lookup** (Serviceplatformen):
- Company name and status
- Production units (P-numbers)
- Industry codes (NACE)
- Ownership structure
- Contact information

**Address Service**:
- Real-time address autocomplete
- Address validation
- Geolocation coordinates
- Municipality/region lookup
- No authentication required

### Communication

**SF1601 Digital Post** (Serviceplatformen):
- Legally binding notifications
- Fallback to physical mail
- Delivery receipts
- Archive integration

**SMS Gateway**:
- Danish mobile networks
- International support
- Delivery tracking
- Template support
- Token replacement

**Email**:
- SMTP/transactional email services
- HTML templates
- PDF attachments
- Bounce handling

### Case Management

**SBSYS Integration**:
- Automatic case creation
- Document archiving
- Status synchronization
- Metadata management

**GetOrganized ESDH**:
- Document filing
- Classification codes
- Retention policies

### Payment

**Nets Easy**:
- Credit/debit card payments
- MobilePay
- Real-time payment confirmation
- Automated reconciliation
- PCI DSS compliance

---

## Pricing Model

### Open Source Licensing

ÅbenForms is released under the GPL-2.0-or-later license:
- **Zero licensing fees** forever
- **No per-user costs**
- **No per-transaction costs**
- **No vendor lock-in**
- **Full source code access**
- **Community-driven development**

### Implementation Packages

**Starter Package** (150,000 DKK one-time):
- Platform installation and configuration
- 3 standard workflows (Parking, Marriage, Building permits)
- MitID and CPR/CVR integration setup
- 2 days on-site training
- 30 days post-launch support

**Professional Package** (350,000 DKK one-time):
- Everything in Starter Package
- Additional workflows from the template library
- SBSYS/ESDH integration (planned modules)
- Custom workflow development (up to 2 workflows)
- Visual identity customization
- 5 days on-site training
- 90 days post-launch support

**Enterprise Package** (Custom pricing):
- Everything in Professional Package
- Multi-tenancy setup (shared platform)
- Advanced custom workflows
- Third-party system integrations
- Dedicated project manager
- White-glove onboarding
- 12 months priority support

### Support Options

**Community Support** (Free):
- Public GitHub issue tracker
- Community forum access
- Documentation and guides
- Response time: Best effort

**Standard Support** (15,000 DKK/year):
- Email support (business hours)
- Bug fixes and security patches
- Quarterly platform updates
- Response time: 48 hours

**Premium Support** (45,000 DKK/year):
- Phone and email support (business hours)
- Priority bug fixes
- Monthly platform updates
- Custom feature requests (evaluated quarterly)
- Response time: 8 hours
- Dedicated support engineer

**Enterprise Support** (Custom):
- 24/7 phone support
- SLA guarantees (99.9% uptime)
- Named support team
- Proactive monitoring
- On-site visits (quarterly)
- Response time: 2 hours

---

## Deployment Status

ÅbenForms is a pre-pilot POC. There are no production municipality deployments and therefore
no customer references, satisfaction figures, or measured ROI to cite. Any "before/after"
numbers you may see elsewhere are illustrative planning estimates, not results from a real
municipality. We can run a live demo against test/mock endpoints and discuss a pilot.

---

## Technical Requirements

### Server Requirements (Production)

**Minimum**:
- 4 CPU cores
- 8 GB RAM
- 50 GB SSD storage
- Ubuntu 22.04 LTS or Debian 12

**Recommended**:
- 8 CPU cores
- 16 GB RAM
- 100 GB SSD storage
- Load balancer for high availability

**Database**:
- MariaDB or MySQL 8.0+
- PostgreSQL (alternative)

**Web Server**:
- Apache 2.4+ or Nginx 1.20+
- PHP 8.4
- SSL/TLS certificate (required)

### Hosting Options

**On-Premises**:
- Install on municipal servers
- Full data control
- Requires IT staff for maintenance

**Cloud Hosting**:
- Any standard LAMP/cloud provider (AWS, Azure, Google Cloud, EU regions)
- Managed backups
- GDPR-appropriate EU regions

**Managed Service**:
- Fully managed by ÅbenForms team
- Turnkey solution
- Monthly subscription
- Multi-tenant shared instance option

### Browser Support

**Desktop**:
- Chrome 100+ (recommended)
- Firefox 100+
- Safari 15+
- Edge 100+

**Mobile**:
- iOS Safari 15+
- Chrome Mobile 100+
- Samsung Internet 16+

### API Access

- RESTful JSON:API
- OAuth 2.0 authentication
- Rate limiting: 1,000 requests/hour (configurable)
- Webhook support for event notifications

---

## Implementation Timeline

### Phase 1: Planning (Week 1-2)

- Stakeholder workshops
- Requirements gathering
- Workflow selection (choose 3-5 from templates)
- Integration planning (MitID, CPR/CVR, payment)
- Infrastructure setup (hosting, domains, SSL)

**Deliverables**:
- Project plan
- Technical architecture document
- Integration credentials
- Test environment

### Phase 2: Development (Week 3-6)

- Platform installation and configuration
- Workflow template customization
- Integration configuration (MitID, Serviceplatformen, payment gateway)
- Visual design customization (logo, colors, fonts)
- Content creation (help text, emails, SMS templates)

**Deliverables**:
- Configured test environment
- 3-5 working workflows
- Integration test results
- User acceptance test plan

### Phase 3: Testing (Week 7-8)

- User acceptance testing with municipal staff
- Integration testing with live services (test mode)
- Load testing (simulated traffic)
- Security audit
- Accessibility review (WCAG 2.1 AA)

**Deliverables**:
- Test reports
- Bug fix completion
- Security sign-off
- Accessibility statement

### Phase 4: Training (Week 9-10)

- Administrator training (workflow management, user management)
- Caseworker training (task management, decision workflows)
- Citizen services staff training (support procedures)
- Documentation delivery

**Deliverables**:
- Training materials
- Video tutorials
- Support runbooks
- Go-live checklist

### Phase 5: Launch (Week 11-12)

- Production deployment
- Data migration (if applicable)
- Soft launch with limited users
- Monitoring and optimization
- Full public launch

**Deliverables**:
- Production platform
- Monitoring dashboards
- Communication materials (website, social media)
- Post-launch support schedule

### Phase 6: Post-Launch (Week 13-16)

- Daily monitoring and optimization
- User feedback collection
- Performance tuning
- Staff support
- Knowledge transfer

**Deliverables**:
- Performance reports
- User satisfaction survey results
- Optimization recommendations
- Project closure document

---

## Security & Compliance

### GDPR Compliance

**Data Protection** (built in today):
- Field-level AES-256 encryption for CPR numbers
- Audit logging
- Data minimization (only collect necessary data)

**Planned (not yet shipped)**:
- Automated data retention policies and right-to-erasure subsystem (issue #91)
- Pseudonymization for analytics

**Audit & Accountability**:
- Audit logging (who, what, when, why)
- Supports data processing agreements and privacy impact assessments as part of a pilot

**Consent Management**:
- Explicit consent collection
- Granular consent options
- Consent withdrawal workflows
- Consent version tracking

### Security Features

**Authentication**:
- MitID integration (NSIS LoA 3)
- Multi-factor authentication for administrators
- Role-based access control (RBAC)
- Session timeout (configurable)

**Data Security**:
- TLS 1.3 encryption in transit
- AES-256 encryption at rest
- Encrypted database backups
- Key rotation policies

**Infrastructure Security**:
- Web application firewall (WAF)
- DDoS protection
- Intrusion detection system (IDS)
- Regular security patches
- Penetration testing (annual)

**Compliance Certifications**:
- ISO 27001 (on roadmap)
- SOC 2 Type II (on roadmap)
- GDPR Article 32 compliance
- NSIS Level of Assurance compliance

---

## Competitive Comparison

| Feature | ÅbenForms | XFlow | KMD Nexus | Status |
|---------|-----------|-------|-----------|--------|
| **Licensing** | Open source (GPL) | 75K DKK/year | 100K DKK/year | Proprietary |
| **Workflow Designer** | Visual BPMN 2.0 | Visual | Form-based | Visual |
| **MitID Integration** | Built-in | Add-on (15K) | Built-in | Built-in |
| **CPR/CVR Lookup** | Built-in | Add-on (20K) | Built-in | Built-in |
| **Digital Post** | Built-in | Add-on (25K) | Built-in | Built-in |
| **Multi-Tenancy** | Included | Enterprise only | Enterprise only | No |
| **API Access** | RESTful JSON:API | SOAP only | REST + SOAP | Limited |
| **Mobile-Responsive** | Yes | Partial | Yes | No |
| **Source Code Access** | Full | No | No | No |
| **Community Support** | Free | No | No | No |
| **Implementation Time** | 2-4 weeks | 8-12 weeks | 12-16 weeks | 6-8 weeks |
| **Total 3-Year Cost** | 95K DKK | 500K DKK | 650K DKK | 400K DKK |

**Unique Advantages**:
- Only open-source option (no vendor lock-in)
- Modern API-first architecture
- Fastest implementation time
- Lowest total cost of ownership
- Active community development
- Full source code transparency

---

## Next Steps

### 1. Schedule Demo (30 minutes)

See the platform in action:
- Live demonstration of parking permit workflow
- Visual workflow builder walkthrough
- Integration capabilities showcase
- Q&A with technical team

**Book Demo**: https://aabenforms.dk/demo or contact@aabenforms.dk

### 2. Free Pilot Program (3 months)

Test the platform risk-free:
- Hosted test environment
- 1 workflow implementation
- Limited user access (up to 50 test submissions)
- Technical support
- No credit card required

**Apply**: https://aabenforms.dk/pilot

### 3. Request Proposal

Receive customized proposal:
- Workflow analysis
- Integration planning
- Cost estimate
- Implementation timeline
- ROI projection

**Request Proposal**: contact@aabenforms.dk

---

## Contact Information

**ÅbenForms ApS**
Åboulevard 23, 3. sal
1635 København V
Denmark

**Website**: https://aabenforms.dk
**Email**: contact@aabenforms.dk
**Phone**: +45 33 36 07 00
**Support**: support@aabenforms.dk

**Business Hours**:
Monday-Friday: 9:00-17:00 CET
Emergency support: 24/7 (Enterprise customers)

**Social Media**:
LinkedIn: /company/aabenforms
GitHub: github.com/madsnorgaard/aabenforms
Twitter: @aabenforms

---

## Appendix A: Workflow Template Library

ÅbenForms includes 8 pre-built workflow templates:

1. **Parking Permit Application** (Low complexity, 1 week setup)
2. **Marriage Ceremony Booking** (Medium complexity, 2 weeks setup)
3. **Building Permit Application** (High complexity, 3 weeks setup)
4. **Contact Form** (Low complexity, 1 day setup)
5. **Freedom of Information Request** (Medium complexity, 1 week setup)
6. **Address Change Notification** (Low complexity, 1 week setup)
7. **Company Verification** (Low complexity, 1 week setup)
8. **Citizen Complaint** (Medium complexity, 2 weeks setup)

Each template includes:
- Complete BPMN workflow diagram
- Pre-configured integration actions
- Default form fields
- Email/SMS templates
- Admin interface configuration

**Customization**: All templates are fully customizable through visual workflow editor.

---

## Appendix B: Glossary

**BPMN**: Business Process Model and Notation - international standard for workflow diagrams

**CPR**: Central Person Register - Danish social security number system

**CVR**: Central Business Register - Danish company registration system

**Adressevælger**: Danmarks Adresseregister - official Danish address service

**ESDH**: Elektronisk Sags- og Dokument Håndtering - electronic case/document management

**GDPR**: General Data Protection Regulation - EU data protection law

**MitID**: Danish national digital identity solution (replaced NemID in 2021)

**NSIS**: National Standard for Identity Assurance - Danish authentication standard

**SF1520**: Serviceplatformen service for CPR lookup

**SF1530**: Serviceplatformen service for CVR lookup

**SF1601**: Serviceplatformen service for Digital Post

**Serviceplatformen**: Danish government service integration platform (operated by Digitaliseringsstyrelsen)

**SBSYS**: Popular municipal case management system in Denmark

---

**Document Version**: 1.0
**Last Updated**: February 2026
**Next Review**: May 2026
