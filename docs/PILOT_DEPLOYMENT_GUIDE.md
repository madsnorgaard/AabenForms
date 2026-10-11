# ÅbenForms Pilot Deployment Guide

> **Superseded for go-live decisions** by [runbooks/PILOT_GO_LIVE.md](runbooks/PILOT_GO_LIVE.md), the short and current list. This document is kept as background and is partly out of date.

**Version**: 1.0
**Last Updated**: February 2026
**Target Audience**: Project Managers, Municipal IT Coordinators

This guide provides a step-by-step plan for piloting ÅbenForms in a municipality, ensuring a smooth transition from existing systems to the new workflow automation platform.

---

## Table of Contents

1. [Pilot Overview](#pilot-overview)
2. [Municipality Selection Criteria](#municipality-selection-criteria)
3. [Pre-Pilot Preparation](#pre-pilot-preparation)
4. [Step-by-Step Deployment Plan](#step-by-step-deployment-plan)
5. [Municipality Onboarding Process](#municipality-onboarding-process)
6. [Data Migration](#data-migration)
7. [User Training Plan](#user-training-plan)
8. [Parallel Testing Strategy](#parallel-testing-strategy)
9. [Success Metrics and KPIs](#success-metrics-and-kpis)
10. [Feedback Collection Process](#feedback-collection-process)
11. [Go/No-Go Criteria](#go-no-go-criteria)
12. [Pilot Timeline](#pilot-timeline)
13. [Support During Pilot](#support-during-pilot)

---

## Pilot Overview

### Objectives

The ÅbenForms pilot program aims to:

1. **Validate technical functionality** in real-world municipal environment
2. **Test user acceptance** with actual case workers and citizens
3. **Identify integration issues** with existing municipal systems
4. **Refine workflows** based on municipal-specific requirements
5. **Gather metrics** on efficiency gains and user satisfaction
6. **Build confidence** for full national rollout

### Pilot Scope

**In Scope**:
- 3 core workflows:
  1. Parking Permit Application
  2. Daycare Enrollment (Dual Parent Approval)
  3. Building Permit Application (Simple cases)
- MitID authentication
- CPR/CVR lookup via Serviceplatformen
- Email notifications
- Case worker portal
- Citizen portal

**Out of Scope** (Pilot Phase):
- Digital Post integration (added in Phase 2)
- SBSYS/GetOrganized integration (added in Phase 3)
- Payment processing (added in Phase 2)
- Advanced BPMN workflows (custom development)

### Pilot Duration

**Recommended**: 12 weeks (3 months)

- **Weeks 1-2**: Preparation and setup
- **Weeks 3-4**: Training and onboarding
- **Weeks 5-10**: Active pilot (parallel testing)
- **Weeks 11-12**: Evaluation and decision

---

## Municipality Selection Criteria

### Ideal Pilot Municipality Profile

**Technical Readiness**:
- [ ] IT department with at least 2 full-time staff
- [ ] Existing use of digital services (not primarily paper-based)
- [ ] Comfortable with beta/pilot programs
- [ ] Ability to dedicate test servers or use Platform.sh staging

**Organizational Readiness**:
- [ ] Executive-level buy-in (municipal director or IT director)
- [ ] Dedicated project champion within the municipality
- [ ] Willingness to provide weekly feedback
- [ ] Flexibility to adapt internal processes

**Size Considerations**:
- **Small Municipality** (5,000-15,000 citizens): Easy to manage, limited complexity
- **Medium Municipality** (15,000-50,000 citizens): **Recommended** - good balance
- **Large Municipality** (50,000+ citizens): High complexity, potential for significant impact

**Geographic Diversity**:
- Aim for 3-5 pilot municipalities across Denmark:
  - 1 urban (e.g., suburban Copenhagen)
  - 1 medium-sized city (e.g., Odense, Aarhus suburb)
  - 1 rural (e.g., Zealand or Jutland)

### Recommended Pilot Municipalities

**Example candidates** (fictional for demonstration):

1. **Ballerup Kommune** (Metropolitan area, 48,000 citizens)
   - Strong IT department
   - History of digital innovation
   - Suburban mix of demographics

2. **Svendborg Kommune** (Medium city, 58,000 citizens)
   - Island municipality (Funen)
   - Mix of urban and rural
   - Existing digital services

3. **Vordingborg Kommune** (Rural, 45,000 citizens)
   - Southern Zealand
   - Aging population (good accessibility test)
   - Traditional processes (good transformation test)

---

## Pre-Pilot Preparation

### Month Before Pilot (Week -4 to Week 0)

#### Technical Preparation

**Week -4: Environment Setup**

- [ ] **Provision pilot environment**
  ```bash
  # Create Platform.sh pilot environment
  platform environment:branch pilot-ballerup main
  platform environment:activate pilot-ballerup
  ```

- [ ] **Configure multi-tenancy**
  ```bash
  # Create tenant domain
  drush domain:create ballerup.aabenforms.dk "Ballerup Kommune"
  drush domain:default ballerup.aabenforms.dk
  ```

- [ ] **Set up staging data**
  - Import sanitized production data (if available)
  - Create test user accounts
  - Configure test MitID credentials

- [ ] **Enable pilot-specific features**
  - Enable debug mode for detailed logging
  - Configure enhanced error reporting
  - Set up pilot-specific analytics tracking

**Week -3: Integration Testing**

- [ ] **Test Serviceplatformen integration**
  - CPR lookup (test environment)
  - CVR lookup (test environment)
  - Verify certificate configuration

- [ ] **Test MitID integration**
  - Personal login (MitID test environment)
  - Business login (MitID Erhverv test)
  - Verify NSIS compliance

- [ ] **Email configuration**
  - Configure SMTP (SendGrid or Mailgun)
  - Test notification delivery
  - Verify Danish templates

**Week -2: User Acceptance Testing (UAT)**

- [ ] **Internal UAT**
  - ÅbenForms team tests all workflows
  - Fix critical bugs
  - Document known issues

- [ ] **Municipality UAT**
  - Invite 3-5 key users from municipality
  - Conduct 2-hour UAT session
  - Collect initial feedback
  - Iterate based on feedback

**Week -1: Final Preparations**

- [ ] **Documentation review**
  - User guides translated to Danish
  - Admin guides reviewed by municipality IT
  - FAQ prepared

- [ ] **Training materials prepared**
  - Video tutorials recorded
  - Hands-on exercises created
  - Quick reference cards printed

- [ ] **Support infrastructure**
  - Pilot support email: pilot-support@aabenforms.dk
  - Slack channel or Teams channel created
  - Helpdesk ticketing system configured

#### Organizational Preparation

**Stakeholder Alignment**

- [ ] **Sign pilot agreement**
  - Scope and deliverables
  - Data protection agreement (DPA)
  - Pilot duration and exit criteria
  - Resource commitments from both parties

- [ ] **Identify key roles**
  - Municipality project lead
  - IT liaison
  - Case worker representatives (3-5 per department)
  - Citizen test group (20-30 volunteers)

- [ ] **Communication plan**
  - Internal announcement to municipal staff
  - Public announcement (website, social media)
  - Citizen volunteer recruitment
  - Weekly status meeting schedule

---

## Step-by-Step Deployment Plan

### Week 1-2: Setup and Configuration

#### Day 1: Kickoff Meeting

**Attendees**:
- ÅbenForms project team (3-5 people)
- Municipality project lead
- IT department head
- Department heads (affected departments)

**Agenda** (2 hours):
1. Introductions (15 min)
2. Pilot objectives and scope (20 min)
3. Timeline and milestones (15 min)
4. Roles and responsibilities (15 min)
5. Communication plan (15 min)
6. Q&A (30 min)
7. Next steps (10 min)

**Deliverables**:
- Signed kickoff memo
- Contact list
- Meeting schedule (weekly status calls)

#### Day 2-5: Technical Setup

**ÅbenForms Team Tasks**:

```bash
# 1. Create tenant configuration
cd /var/www/aabenforms/backend
drush domain:create ballerup.aabenforms.dk "Ballerup Kommune"

# 2. Configure tenant-specific settings
drush config:set aabenforms_tenant.settings.ballerup \
  municipality_name "Ballerup Kommune" \
  cvr_number "29189498" \
  contact_email "borgerservice@ballerup.dk" \
  logo_url "/sites/default/files/ballerup-logo.png"

# 3. Import workflow templates
drush aabenforms:import-workflow parking_permit ballerup
drush aabenforms:import-workflow daycare_enrollment ballerup
drush aabenforms:import-workflow building_permit ballerup

# 4. Create user roles
drush role:create ballerup_case_worker "Ballerup Sagsbehandler"
drush role:perm-add ballerup_case_worker "access ballerup content"

# 5. Create test users
drush user:create ballerup_admin --mail="admin@ballerup.dk" --password="SECURE_PASSWORD"
drush user:role-add ballerup_case_worker ballerup_admin
```

**Municipality IT Tasks**:

- [ ] Configure DNS (CNAME for subdomain)
  ```dns
  ballerup.aabenforms.dk    CNAME    aabenforms.dk
  ```

- [ ] Whitelist ÅbenForms IPs in firewall
  ```
  Source: aabenforms.dk (IP: xxx.xxx.xxx.xxx)
  Destination: Serviceplatformen APIs
  Ports: 443 (HTTPS)
  ```

- [ ] Provide SSL certificates (if self-hosted)

- [ ] Create VPN tunnel (if accessing internal systems)

#### Day 6-10: Workflow Customization

**Customize workflows to municipality-specific requirements**:

**Example: Ballerup Parking Permit**

1. **Adjust fields**:
   - Add field: "Preferred parking zone" (dropdown)
   - Add field: "Vehicle registration plate" (text)
   - Remove field: Not applicable

2. **Update approval logic**:
   - Add rule: Auto-approve for residents in Zone A
   - Add rule: Manual review for commercial vehicles

3. **Configure notifications**:
   - Email template with Ballerup branding
   - SMS notifications (optional)

**BPMN Workflow Updates**:

```xml
<!-- Add custom Ballerup decision gateway -->
<bpmn:exclusiveGateway id="Gateway_ParkingZone" name="Parking Zone?">
  <bpmn:incoming>Flow_AfterValidation</bpmn:incoming>
  <bpmn:outgoing>Flow_ZoneA</bpmn:outgoing>
  <bpmn:outgoing>Flow_OtherZones</bpmn:outgoing>
</bpmn:exclusiveGateway>

<bpmn:sequenceFlow id="Flow_ZoneA" sourceRef="Gateway_ParkingZone" targetRef="Task_AutoApprove">
  <bpmn:conditionExpression xsi:type="bpmn:tFormalExpression">
    ${zone == 'A'}
  </bpmn:conditionExpression>
</bpmn:sequenceFlow>
```

**Testing**:
```bash
# Test workflow execution
drush aabenforms:test-workflow parking_permit --municipality=ballerup

# Verify decision logic
drush aabenforms:validate-workflow parking_permit
```

---

## Municipality Onboarding Process

### User Account Setup

**Case Workers** (20-30 accounts):

```bash
# Bulk import from CSV
# CSV format: name,email,department,role
drush aabenforms:import-users ballerup-users.csv

# Example CSV:
# "Jens Hansen","jens.hansen@ballerup.dk","Teknik og Miljø","case_worker"
# "Marie Nielsen","marie.nielsen@ballerup.dk","Børn og Skole","case_worker"
```

**Administrators** (2-3 accounts):

- Full access to all workflows
- Can configure workflows
- Can manage users

**Citizens** (test group - 20-30 volunteers):

- MitID login (production credentials)
- Self-service portal access
- Volunteer agreement signed

### Department-Specific Configuration

**Teknik og Miljø (Building and Environment)**:
- Workflows: Building Permit, Parking Permit
- Integration: GIS system (for zoning validation)
- Custom fields: Cadastral number, plot size

**Børn og Skole (Children and Schools)**:
- Workflows: Daycare Enrollment
- Integration: Daycare capacity system
- Custom fields: Sibling priority, special needs

**Borgerservice (Citizen Service)**:
- Workflows: All (read-only for support)
- Access level: View-only

### Branding and Localization

**Visual Branding**:
```bash
# Upload municipality logo
drush media:create --name="Ballerup Logo" \
  --bundle=image \
  --file=/path/to/ballerup-logo.png

# Configure theme colors
drush config:set aabenforms_tenant.settings.ballerup \
  primary_color "#005ca9" \
  secondary_color "#ffcc00"
```

**Email Templates**:
- Customize email footer with Ballerup contact info
- Add municipality logo to email header
- Translate system messages to Danish (if not already)

**Citizen Portal**:
- Custom homepage message
- Municipality-specific FAQ
- Links to Ballerup's main website

---

## Data Migration

### Assessment Phase

**Identify Data Sources**:

- [ ] **Existing systems**:
  - Legacy form system (e.g., Adobe FormServer)
  - Case management system (e.g., SBSYS)
  - Spreadsheets (parking permits, daycare lists)

- [ ] **Data types**:
  - Historical submissions (last 2 years)
  - Active cases (in-progress applications)
  - User accounts (case workers)
  - Configuration (workflow rules, approval matrices)

### Migration Strategy

**Recommended Approach**: **Phased Migration**

**Phase 1**: Configuration Only (Week 1-2)
- Migrate workflow configurations
- Migrate user accounts
- NO historical data (fresh start)

**Phase 2**: Active Cases (Week 3-4, optional)
- Migrate in-progress applications
- Manually recreate active cases in ÅbenForms
- Small dataset (10-20 cases)

**Phase 3**: Historical Data (Post-Pilot, optional)
- Archive historical data
- Provide read-only access via reporting module
- No need to recreate old workflows

### Migration Scripts

**User Account Migration**:

```php
<?php
// scripts/migrate-users.php

use Drupal\user\Entity\User;

$csv = fopen('ballerup-users.csv', 'r');
fgetcsv($csv); // Skip header

while (($row = fgetcsv($csv)) !== FALSE) {
  [$name, $email, $department, $role] = $row;

  $user = User::create([
    'name' => $email,
    'mail' => $email,
    'status' => 1,
    'field_full_name' => $name,
    'field_department' => $department,
  ]);

  $user->addRole($role);
  $user->save();

  echo "Created user: $name ($email)\n";
}

fclose($csv);
```

**Run migration**:
```bash
drush php:script scripts/migrate-users.php
```

**Workflow Configuration Migration**:

If the municipality has existing workflow rules in another system:

```bash
# Export existing workflows to JSON
# (Manual process - review with municipality)

# Import custom workflows
drush aabenforms:import-workflow-config ballerup-parking-permit.json

# Validate imported workflow
drush aabenforms:validate-workflow parking_permit --municipality=ballerup
```

### Data Migration Checklist

- [ ] Data inventory completed
- [ ] Migration scope agreed with municipality
- [ ] Migration scripts developed and tested
- [ ] Backup of source data created
- [ ] Test migration on staging environment
- [ ] Municipality approval on migrated data
- [ ] Production migration scheduled
- [ ] Rollback plan prepared

---

## User Training Plan

### Training Audience

**Group 1: Administrators** (2-3 people)
- **Duration**: 1 full day (8 hours)
- **Focus**: System administration, workflow configuration, user management

**Group 2: Case Workers** (20-30 people)
- **Duration**: Half day (4 hours)
- **Focus**: Processing applications, using case worker portal

**Group 3: Citizens** (Test volunteers)
- **Duration**: 1 hour (optional webinar)
- **Focus**: Submitting applications, MitID login

### Training Schedule

**Week 3: Administrator Training**

**Day 1: Full-Day Training** (9:00 - 17:00)

| Time | Topic | Format |
|------|-------|--------|
| 09:00-09:30 | Welcome & Overview | Presentation |
| 09:30-10:30 | System Architecture | Presentation + Demo |
| 10:30-10:45 | *Coffee Break* | |
| 10:45-12:00 | User Management | Hands-on Lab |
| 12:00-13:00 | *Lunch* | |
| 13:00-14:30 | Workflow Configuration | Hands-on Lab |
| 14:30-14:45 | *Coffee Break* | |
| 14:45-16:00 | Monitoring & Troubleshooting | Demo + Hands-on |
| 16:00-17:00 | Q&A and Advanced Topics | Discussion |

**Hands-on Lab 1: User Management**
- Create new user account
- Assign roles and permissions
- Deactivate user
- Reset password

**Hands-on Lab 2: Workflow Configuration**
- Import workflow template
- Customize form fields
- Modify approval logic
- Test workflow execution

**Week 4: Case Worker Training**

**Session 1** (Group A: 20 people)
**Session 2** (Group B: 20 people)

**Half-Day Training** (9:00 - 13:00)

| Time | Topic | Format |
|------|-------|--------|
| 09:00-09:15 | Welcome & Pilot Overview | Presentation |
| 09:15-10:00 | System Navigation | Demo |
| 10:00-10:15 | *Coffee Break* | |
| 10:15-11:30 | Processing Applications | Hands-on Lab |
| 11:30-12:00 | Parking Permit Workflow | Role-play Exercise |
| 12:00-13:00 | *Lunch & Q&A* | |

**Hands-on Lab: Processing Applications**
- Log in with MitID
- View pending applications
- Review application details
- Approve/reject application
- Send notification to citizen

**Role-play Exercise**:
- Trainer plays citizen submitting parking permit
- Trainee processes application end-to-end
- Group discusses edge cases and questions

**Week 4: Citizen Training (Optional)**

**Webinar** (18:00 - 19:00, evening session)

| Time | Topic | Format |
|------|-------|--------|
| 18:00-18:10 | Welcome & Why ÅbenForms? | Presentation |
| 18:10-18:30 | How to Submit Application | Screen share demo |
| 18:30-18:45 | MitID Login Walkthrough | Demo |
| 18:45-19:00 | Q&A | Discussion |

**Follow-up**:
- Email with step-by-step PDF guide
- Link to video tutorial
- Helpdesk contact information

### Training Materials

**Provided by ÅbenForms**:

- [ ] **Administrator Guide** (50-page PDF)
  - System overview
  - User management
  - Workflow configuration
  - Troubleshooting

- [ ] **Case Worker Guide** (30-page PDF)
  - Getting started
  - Processing applications
  - Common scenarios
  - FAQ

- [ ] **Citizen Guide** (10-page PDF, Danish)
  - How to create account
  - How to submit application
  - How to track status
  - MitID help

- [ ] **Video Tutorials** (5-10 minutes each)
  - "Welcome to ÅbenForms" (3 min)
  - "How to Process a Parking Permit" (7 min)
  - "How to Configure a Workflow" (10 min)
  - "How to Submit an Application" (5 min)

- [ ] **Quick Reference Cards** (Printed, laminated)
  - Case worker shortcuts
  - Admin common tasks

### Post-Training Support

**Week 5 onwards**:

- [ ] **Daily office hours** (first week after training)
  - Time: 10:00-11:00 and 14:00-15:00
  - Format: Video call (Teams or Zoom)
  - Purpose: Answer questions, troubleshoot issues

- [ ] **Weekly drop-in sessions** (weeks 2-10)
  - Time: Tuesdays 14:00-15:00
  - Format: Optional video call
  - Purpose: Advanced topics, tips & tricks

- [ ] **On-demand support**
  - Email: pilot-support@aabenforms.dk
  - Response time: < 4 hours (business hours)
  - Slack/Teams channel for quick questions

---

## Parallel Testing Strategy

### Why Parallel Testing?

Parallel testing (running old and new systems side-by-side) provides:

1. **Safety net**: Municipality can revert to old system if needed
2. **Data validation**: Compare outputs from both systems
3. **User confidence**: Gradual transition reduces anxiety
4. **Risk mitigation**: Catches discrepancies before full cutover

### Parallel Testing Phases

**Phase 1: Shadow Mode** (Weeks 5-6)

- **Old system**: Primary (100% of real cases)
- **New system**: Secondary (duplicating cases for testing)
- **Case workers**: Process each case in BOTH systems
- **Citizens**: Use old system only

**Workflow**:
```
Citizen submits application (old system)
  ↓
Case worker manually recreates in ÅbenForms
  ↓
Case worker processes in BOTH systems
  ↓
Compare results (should be identical)
  ↓
Approve in old system (official decision)
```

**Success Criteria**:
- 100% of cases successfully recreated in ÅbenForms
- Decision outcomes match in both systems
- Case workers comfortable with ÅbenForms interface

**Phase 2: Pilot Mode** (Weeks 7-8)

- **Old system**: Primary (80% of cases)
- **New system**: Primary for pilot cases (20% of cases)
- **Case workers**: Process pilot cases ONLY in ÅbenForms
- **Citizens**: Pilot volunteers use ÅbenForms, others use old system

**Workflow**:
```
Citizen volunteers submit to ÅbenForms
  ↓
Case worker processes ONLY in ÅbenForms
  ↓
Decision is OFFICIAL (no old system involved)
  ↓
Monitor for issues, collect feedback
```

**Success Criteria**:
- 20% of cases processed successfully in ÅbenForms
- No major incidents or data loss
- Positive feedback from citizens and case workers

**Phase 3: Majority Mode** (Weeks 9-10)

- **Old system**: Fallback (20% of cases)
- **New system**: Primary (80% of cases)
- **Case workers**: Default to ÅbenForms, use old system only for complex/legacy cases
- **Citizens**: Most use ÅbenForms, old system still available

**Workflow**:
```
Citizens use ÅbenForms by default
  ↓
Case workers process in ÅbenForms
  ↓
Complex edge cases: escalate to old system
  ↓
Monitor edge cases, refine workflows
```

**Success Criteria**:
- 80% of cases processed in ÅbenForms
- Edge cases documented and addressed
- Old system usage declining

### Parallel Testing Procedures

**Case Worker Checklist (Shadow Mode)**:

For each application:

- [ ] Receive application in old system
- [ ] Manually recreate in ÅbenForms
- [ ] Process in ÅbenForms
- [ ] Process in old system
- [ ] Compare outcomes:
  - [ ] Approval/rejection decision matches
  - [ ] Notification content matches
  - [ ] Processing time comparable
- [ ] Document any discrepancies
- [ ] Submit decision via old system (official)

**Daily Reconciliation**:

```bash
# Export applications from old system
# Export applications from ÅbenForms

# Compare counts
old_system_count=25
aabenforms_count=25

if [ $old_system_count -eq $aabenforms_count ]; then
  echo " Counts match"
else
  echo " Discrepancy: $old_system_count vs $aabenforms_count"
fi

# Compare outcomes (manual review)
diff old_system_decisions.csv aabenforms_decisions.csv
```

### Handling Discrepancies

**If outcomes differ**:

1. **Document the case**
   - Case ID in both systems
   - Application details
   - Decision in old system
   - Decision in ÅbenForms
   - Reason for difference

2. **Analyze root cause**
   - Data entry error?
   - Workflow logic error?
   - Integration issue?
   - Business rule interpretation?

3. **Escalate to ÅbenForms team**
   - Report via Slack/Teams
   - ÅbenForms team investigates within 24 hours
   - Fix deployed if needed

4. **Re-test case**
   - Recreate scenario in staging
   - Verify fix
   - Document in lessons learned

---

## Success Metrics and KPIs

### Quantitative Metrics

**Efficiency Metrics**:

| Metric | Baseline (Old System) | Target (ÅbenForms) | Measurement Method |
|--------|----------------------|-------------------|-------------------|
| **Average processing time** | 3.5 days | 2.0 days | Timestamp: submission → decision |
| **Case worker time per application** | 45 minutes | 20 minutes | Manual tracking |
| **Citizen time to submit** | 30 minutes | 10 minutes | User survey |
| **Applications per week** | 50 | 75 | System reports |
| **Approval rate** | 85% | 85% (maintain) | System reports |

**Quality Metrics**:

| Metric | Baseline | Target | Measurement Method |
|--------|----------|--------|-------------------|
| **Data entry errors** | 12% | 3% | Manual audit (sample of 50 cases) |
| **Re-submission rate** | 18% | 5% | System tracking |
| **Missing information rate** | 22% | 5% | Workflow validation reports |
| **SLA compliance** | 72% | 95% | System reports |

**User Experience Metrics**:

| Metric | Target | Measurement Method |
|--------|--------|-------------------|
| **Citizen satisfaction** | ≥ 4.0 / 5.0 | Post-submission survey |
| **Case worker satisfaction** | ≥ 4.0 / 5.0 | Weekly pulse survey |
| **System availability** | ≥ 99.5% | Uptime monitoring |
| **Page load time** | < 2 seconds | Performance monitoring |

### Qualitative Metrics

**Citizen Feedback** (Post-submission survey):

1. How easy was it to submit your application? (1-5 scale)
2. How clear were the instructions? (1-5 scale)
3. What did you like most about the new system?
4. What could be improved?
5. Would you recommend this system to others? (Yes/No)

**Case Worker Feedback** (Weekly pulse survey):

1. How confident do you feel using ÅbenForms? (1-5 scale)
2. How does ÅbenForms compare to the old system? (Better/Same/Worse)
3. What feature do you use most?
4. What feature needs improvement?
5. Any issues or blockers this week?

### Data Collection Methods

**Automated Tracking**:

```php
// Track application processing time
$submission = \Drupal::entityTypeManager()
  ->getStorage('webform_submission')
  ->load($submission_id);

$created = $submission->getCreatedTime();
$completed = $submission->getCompletedTime();
$processing_time = $completed - $created;

// Log to analytics
\Drupal::service('aabenforms.analytics')->track('processing_time', [
  'workflow' => 'parking_permit',
  'municipality' => 'ballerup',
  'duration_seconds' => $processing_time,
]);
```

**Manual Tracking** (Case Worker Log):

```csv
Date,Case_ID,Workflow,Start_Time,End_Time,Duration_Minutes,Notes
2026-02-10,PP-001,parking_permit,10:00,10:22,22,"Easy case"
2026-02-10,PP-002,parking_permit,10:30,11:15,45,"Needed CPR lookup"
```

**Weekly Reporting**:

```bash
# Generate weekly KPI report
drush aabenforms:report --municipality=ballerup --start-date=2026-02-03 --end-date=2026-02-09

# Output:
# Applications submitted: 52
# Applications approved: 38
# Applications rejected: 6
# Applications pending: 8
# Average processing time: 1.8 days
# Citizen satisfaction: 4.2/5.0
# Case worker satisfaction: 4.0/5.0
```

---

## Feedback Collection Process

### Continuous Feedback Loops

**Daily** (Weeks 5-6, Shadow Mode):
- Quick Slack/Teams check-in: "Any blockers today?"
- Response time: Within 1 hour

**Weekly** (Weeks 5-10, Active Pilot):
- Status call: Tuesdays 14:00-15:00 (1 hour)
- Attendees: Municipality project lead, ÅbenForms team, key case workers
- Agenda:
  1. Metrics review (15 min)
  2. Issues and blockers (20 min)
  3. Wins and positive feedback (10 min)
  4. Next week's focus (10 min)
  5. Q&A (5 min)

**Bi-Weekly** (Weeks 5-10):
- Case worker roundtable: Fridays 10:00-11:00
- Informal discussion, tips & tricks sharing
- Pizza provided (virtual or in-person)

### Feedback Channels

**Structured Feedback**:

1. **Weekly Pulse Survey** (case workers)
   - Sent every Friday at 16:00
   - 5 questions, takes 2 minutes
   - Anonymous option available
   - Tool: Google Forms or Typeform

2. **Post-Submission Survey** (citizens)
   - Triggered after application submission
   - 5 questions, takes 3 minutes
   - Optional, not required
   - Incentive: Entry into prize draw (optional)

**Unstructured Feedback**:

1. **Slack/Teams Channel**: `#pilot-ballerup`
   - Real-time questions and feedback
   - ÅbenForms team monitors during business hours

2. **Email**: `pilot-support@aabenforms.dk`
   - For detailed issues or private feedback
   - Response SLA: < 4 hours

3. **Monthly Town Hall** (optional)
   - Open forum for all municipality staff
   - Presentation of progress and metrics
   - Q&A session

### Feedback Processing

**Triage Process**:

1. **Collect** feedback from all channels (daily)
2. **Categorize** by type:
   - Bug (technical issue)
   - Feature request (enhancement)
   - Documentation gap (unclear instructions)
   - Training need (user doesn't know how to do something)
3. **Prioritize** by severity:
   - P1 (Critical): Blocking work, immediate fix needed
   - P2 (High): Workaround exists, fix within 48 hours
   - P3 (Medium): Minor inconvenience, fix within 1 week
   - P4 (Low): Nice-to-have, backlog for post-pilot
4. **Assign** to appropriate team member
5. **Resolve** and communicate back to reporter
6. **Document** in lessons learned log

**Feedback Tracking**:

```bash
# Feedback log (GitHub Issues or Jira)
Issue #23: "CPR lookup button not visible on mobile"
  - Reported by: Marie Nielsen (case worker)
  - Date: 2026-02-12
  - Category: Bug
  - Priority: P2 (High)
  - Assigned to: Frontend developer
  - Status: In progress
  - Resolution: Fixed in release 1.2.3 (2026-02-14)
  - Communicated to reporter: Yes (2026-02-14)
```

---

## Go/No-Go Criteria

### Week 11: Mid-Pilot Review

**Go/No-Go Decision Point 1**: Continue with pilot or revert to old system?

**GO Criteria** (must meet ALL):

- [ ] **Technical Stability**
  - System availability ≥ 99.0%
  - No critical (P1) bugs outstanding
  - Data integrity verified (no data loss)

- [ ] **User Adoption**
  - ≥ 80% of case workers actively using system
  - ≥ 50% of citizen volunteers have submitted applications
  - Case worker satisfaction ≥ 3.5 / 5.0

- [ ] **Business Value**
  - Average processing time reduced by ≥ 20%
  - ≥ 90% of applications processed successfully
  - No increase in error rate

**NO-GO Criteria** (any ONE triggers revert):

- [ ] **Critical Failures**
  - Data loss or corruption
  - Security breach
  - System availability < 95%

- [ ] **User Rejection**
  - Case worker satisfaction < 3.0 / 5.0
  - ≥ 50% of case workers refusing to use system
  - Executive-level request to halt pilot

**Decision**: [   ] GO  [   ] NO-GO

**Signed by**:
- Municipality Project Lead: _________________ Date: _______
- ÅbenForms Project Lead: _________________ Date: _______

### Week 12: End-of-Pilot Review

**Go/No-Go Decision Point 2**: Proceed to full rollout or abandon?

**GO Criteria** (must meet ≥ 80%):

- [ ] **Technical Success**
  - System availability ≥ 99.5%
  - All critical and high-priority bugs resolved
  - Performance metrics within targets
  - Integration with Serviceplatformen working reliably

- [ ] **User Success**
  - Case worker satisfaction ≥ 4.0 / 5.0
  - Citizen satisfaction ≥ 4.0 / 5.0
  - ≥ 90% of case workers proficient in using system
  - Positive feedback outweighs negative 3:1

- [ ] **Business Success**
  - Average processing time reduced by ≥ 30%
  - Case worker time per application reduced by ≥ 40%
  - Application throughput increased by ≥ 20%
  - Data quality improved (error rate reduced by ≥ 50%)

- [ ] **Organizational Success**
  - Executive-level buy-in maintained
  - Department heads supportive
  - Budget approved for full rollout
  - Change management successful

**NO-GO Criteria** (any ONE triggers abandonment):

- [ ] **Fundamental Issues**
  - Technical architecture not scalable
  - GDPR compliance concerns
  - Integration failures with critical systems
  - User rejection despite training and support

- [ ] **Business Case Failure**
  - No measurable efficiency gains
  - Increased costs without benefits
  - Negative ROI projection

**Decision**: [   ] GO (Full Rollout)  [   ] NO-GO (Revert to Old System)

**Signed by**:
- Municipality Director: _________________ Date: _______
- ÅbenForms CEO: _________________ Date: _______

---

## Pilot Timeline

### Visual Timeline

```
Week -4 to 0: PREPARATION
├─ Week -4: Environment setup
├─ Week -3: Integration testing
├─ Week -2: UAT with municipality
└─ Week -1: Final preparations

Week 1-2: SETUP & TRAINING
├─ Day 1: Kickoff meeting
├─ Day 2-5: Technical setup
├─ Day 6-10: Workflow customization
├─ Week 3: Administrator training
└─ Week 4: Case worker & citizen training

Week 5-10: ACTIVE PILOT
├─ Week 5-6: Shadow Mode (100% old, 100% new for testing)
├─ Week 7-8: Pilot Mode (80% old, 20% new official)
├─ Week 9-10: Majority Mode (20% old, 80% new)
└─ Week 11: Mid-pilot review → GO/NO-GO Decision 1

Week 11-12: EVALUATION
├─ Week 11: Data analysis and reporting
├─ Week 12: Final evaluation and decision
└─ Week 12 End: GO/NO-GO Decision 2 → Full Rollout or Revert
```

### Detailed Weekly Breakdown

| Week | Phase | Key Activities | Deliverables |
|------|-------|---------------|--------------|
| -4 | Prep | Environment setup, multi-tenancy config | Pilot environment live |
| -3 | Prep | Integration testing (MitID, Serviceplatformen) | Integration confirmed |
| -2 | Prep | UAT with municipality users | Bug fixes, initial feedback |
| -1 | Prep | Final preparations, training materials | Training materials ready |
| 1 | Setup | Kickoff, technical setup | Signed kickoff memo |
| 2 | Setup | Workflow customization, user account creation | Workflows customized |
| 3 | Training | Administrator training (full day) | Admins certified |
| 4 | Training | Case worker training (2 sessions) | Case workers trained |
| 5 | Pilot | Shadow mode begins | Daily reconciliation reports |
| 6 | Pilot | Shadow mode continues | Shadow mode complete |
| 7 | Pilot | Pilot mode begins (20% official) | Pilot mode metrics |
| 8 | Pilot | Pilot mode continues | Pilot mode complete |
| 9 | Pilot | Majority mode begins (80% official) | Majority mode metrics |
| 10 | Pilot | Majority mode continues | Majority mode complete |
| 11 | Evaluation | Mid-pilot review, GO/NO-GO Decision 1 | Decision memo |
| 12 | Evaluation | Final analysis, GO/NO-GO Decision 2 | Final report, decision |

---

## Support During Pilot

### Support Team Structure

**Tier 1: Helpdesk** (Municipality IT)
- First point of contact for basic questions
- Password resets, account creation
- Basic troubleshooting (clear cache, browser issues)
- Escalate to Tier 2 if needed

**Tier 2: ÅbenForms Support Team**
- Technical issues (bugs, errors)
- Workflow configuration support
- Integration issues (MitID, Serviceplatformen)
- Escalate to Tier 3 if needed

**Tier 3: ÅbenForms Development Team**
- Critical bugs requiring code changes
- Architecture or design issues
- Emergency fixes (deployed within 4 hours)

### Support SLAs

| Priority | Response Time | Resolution Time | Escalation |
|----------|--------------|----------------|------------|
| **P1 (Critical)** | 1 hour | 4 hours | Immediate |
| **P2 (High)** | 4 hours | 24 hours | 8 hours |
| **P3 (Medium)** | 1 business day | 1 week | 3 days |
| **P4 (Low)** | 2 business days | Backlog | N/A |

**Priority Definitions**:
- **P1**: System down, data loss, security breach
- **P2**: Major functionality broken, workaround exists
- **P3**: Minor functionality impaired
- **P4**: Cosmetic issue, feature request

### Support Channels

**Email**: `pilot-support@aabenforms.dk`
- Monitored 24/7 (P1 only)
- Business hours: Mon-Fri 8:00-17:00

**Slack/Teams**: `#pilot-ballerup`
- Real-time chat for quick questions
- ÅbenForms team online: Mon-Fri 9:00-16:00

**Phone**: +45 XX XX XX XX
- Emergency hotline (P1 issues only)
- Available 24/7 during pilot

**Video Call**: Scheduled or ad-hoc
- Tool: Microsoft Teams or Zoom
- For complex issues requiring screen sharing

### Knowledge Base

**Self-Service Resources**:

- **FAQ**: https://support.aabenforms.dk/faq
  - 50+ common questions and answers
  - Searchable, categorized by topic

- **Video Tutorials**: https://support.aabenforms.dk/videos
  - Step-by-step guides
  - Closed captions in Danish

- **Documentation**: https://docs.aabenforms.dk
  - Technical reference
  - API documentation

**Municipality-Specific**:

- **Ballerup Pilot Wiki** (internal)
  - Custom workflows documented
  - Local contact list
  - Municipality-specific FAQ

---

## Conclusion

This pilot deployment guide provides a comprehensive roadmap for successfully implementing ÅbenForms in a pilot municipality. By following these steps, you'll ensure:

- **Technical readiness** through thorough setup and testing
- **User adoption** through comprehensive training and support
- **Risk mitigation** through parallel testing and go/no-go checkpoints
- **Data-driven decisions** through rigorous metrics and feedback collection

**Success Factors**:
1. Executive buy-in and dedicated project champion
2. Realistic expectations and clear scope
3. Comprehensive training and ongoing support
4. Open communication and feedback loops
5. Willingness to iterate and improve

**Next Steps After Pilot**:
- Full rollout to entire municipality (if GO decision)
- Expand to additional municipalities
- Incorporate lessons learned
- Refine workflows based on real-world usage

---

**Document Version**: 1.0
**Last Updated**: February 2026
**Maintained By**: ÅbenForms Project Management Team

**Questions?** Contact: pilot@aabenforms.dk
