<?php

namespace Drupal\Tests\aabenforms_workflows\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * End-to-end tests for dual parent approval workflow.
 *
 * @group aabenforms_workflows
 * @group functional_javascript
 */
class WorkflowApprovalE2ETest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'webform',
    'eca',
    'eca_content',
    'aabenforms_core',
    'aabenforms_workflows',
  ];

  /**
   * Administrator user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * Test webform.
   *
   * @var \Drupal\webform\WebformInterface
   */
  protected $webform;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser([
      'access content',
      'administer workflows',
      'administer webform',
      'administer webform submission',
      'access eca',
    ]);

    // Create a test webform.
    $this->createTestWebform();
  }

  /**
   * Creates a test webform for parent request.
   */
  protected function createTestWebform(): void {
    $this->webform = Webform::create([
      'id' => 'parent_request_form',
      'title' => 'Parent Request Form',
      'elements' => <<<YAML
child_name:
  '#type': textfield
  '#title': 'Child Name'
  '#required': true
child_cpr:
  '#type': textfield
  '#title': 'CPR Number'
  '#required': true
parent1_email:
  '#type': email
  '#title': 'Parent 1 Email'
  '#required': true
parent2_email:
  '#type': email
  '#title': 'Parent 2 Email'
  '#required': true
parents_together:
  '#type': select
  '#title': 'Parents Living Situation'
  '#options':
    together: 'Living Together'
    apart: 'Living Apart'
  '#required': true
request_details:
  '#type': textarea
  '#title': 'Request Details'
  '#required': true
parent1_status:
  '#type': hidden
  '#default_value': 'pending'
parent2_status:
  '#type': hidden
  '#default_value': 'pending'
parent1_comments:
  '#type': hidden
parent2_comments:
  '#type': hidden
YAML
    ]);
    $this->webform->save();
  }

  /**
   * Test complete parent approval workflow end-to-end.
   */
  public function testCompleteParentApprovalWorkflow(): void {
    // 1. Navigate to parent request form.
    $this->drupalGet('/webform/parent_request_form');
    $this->assertSession()->statusCodeEquals(200);

    // 2. Fill and submit form.
    $page = $this->getSession()->getPage();
    $page->fillField('child_name', 'Test Barn Nielsen');
    $page->fillField('child_cpr', '0101121234');
    $page->fillField('parent1_email', 'parent1@test.dk');
    $page->fillField('parent2_email', 'parent2@test.dk');
    $page->selectFieldOption('parents_together', 'together');
    $page->fillField('request_details', 'Test daycare enrollment request');

    $page->pressButton('Submit');

    // 3. Verify submission successful.
    $this->assertSession()->waitForText('Your request has been submitted', 10000);
    $this->assertSession()->pageTextContains('Your request has been submitted');

    // 4. Get submission ID from database.
    $submissions = \Drupal::entityTypeManager()
      ->getStorage('webform_submission')
      ->loadByProperties(['webform_id' => 'parent_request_form']);
    $this->assertNotEmpty($submissions, 'Submission was created');

    /** @var \Drupal\webform\WebformSubmissionInterface $submission */
    $submission = reset($submissions);
    $submission_id = $submission->id();

    // 5. Generate approval token for parent 1.
    $token_service = \Drupal::service('aabenforms_workflows.approval_token');
    $token1 = $token_service->generateToken($submission_id, 1);

    // 6. Parent 1 accesses approval page.
    $this->drupalGet("/parent-approval/1/{$submission_id}/{$token1}");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Test Barn Nielsen');
    $this->assertSession()->pageTextContains('Please log in with MitID');

    // 7. Simulate MitID authentication (mock session).
    $this->mockMitIdAuthentication($submission_id, 1);

    // 8. After MitID auth, see approval form.
    $this->drupalGet("/parent-approval/1/{$submission_id}/{$token1}");
    $this->assertSession()->pageTextContains('Your Decision');
    $this->assertSession()->fieldExists('action');

    // 9. Parent 1 approves.
    $page->selectFieldOption('action', 'approve');
    $page->fillField('comments', 'Approved by parent 1');
    $page->pressButton('Submit Decision');

    // 10. Verify approval recorded.
    $this->assertSession()->waitForText('Your approval has been recorded', 10000);
    $this->assertSession()->pageTextContains('Your approval has been recorded');

    // 11. Repeat for Parent 2.
    $token2 = $token_service->generateToken($submission_id, 2);
    $this->mockMitIdAuthentication($submission_id, 2);
    $this->drupalGet("/parent-approval/2/{$submission_id}/{$token2}");

    $page->selectFieldOption('action', 'approve');
    $page->pressButton('Submit Decision');

    // 12. Verify both parents approved - check submission status.
    $submission = \Drupal::entityTypeManager()
      ->getStorage('webform_submission')
      ->load($submission_id);

    $this->assertEquals('complete', $submission->getElementData('parent1_status'));
    $this->assertEquals('complete', $submission->getElementData('parent2_status'));
    $this->assertEquals('Approved by parent 1', $submission->getElementData('parent1_comments'));
  }

  /**
   * Test parent rejection scenario.
   */
  public function testParentRejectionWorkflow(): void {
    // Submit form.
    $submission = $this->createTestSubmission();
    $submission_id = $submission->id();

    // Generate token for parent 1.
    $token_service = \Drupal::service('aabenforms_workflows.approval_token');
    $token1 = $token_service->generateToken($submission_id, 1);

    // Mock MitID authentication.
    $this->mockMitIdAuthentication($submission_id, 1);

    // Parent 1 accesses approval page.
    $this->drupalGet("/parent-approval/1/{$submission_id}/{$token1}");

    // Parent 1 rejects.
    $page = $this->getSession()->getPage();
    $page->selectFieldOption('action', 'reject');
    $page->fillField('comments', 'Cannot approve at this time');
    $page->pressButton('Submit Decision');

    // Verify rejection recorded.
    $this->assertSession()->waitForText('Your response has been recorded', 10000);
    $this->assertSession()->pageTextContains('Your response has been recorded');

    // Verify workflow status.
    $submission = \Drupal::entityTypeManager()
      ->getStorage('webform_submission')
      ->load($submission_id);
    $this->assertEquals('rejected', $submission->getElementData('parent1_status'));
    $this->assertEquals('Cannot approve at this time', $submission->getElementData('parent1_comments'));
  }

  /**
   * Test invalid token handling.
   */
  public function testInvalidTokenAccess(): void {
    $submission = $this->createTestSubmission();
    $submission_id = $submission->id();

    // Try to access with invalid token.
    $this->drupalGet("/parent-approval/1/{$submission_id}/invalid_token");
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Test expired token handling.
   */
  public function testExpiredTokenAccess(): void {
    $submission = $this->createTestSubmission();
    $submission_id = $submission->id();

    // Create token that's 8 days old (past 7-day expiry).
    $old_timestamp = time() - (8 * 24 * 60 * 60);
    $token_service = \Drupal::service('aabenforms_workflows.approval_token');
    $old_token = $token_service->generateToken($submission_id, 1, $old_timestamp);

    $this->drupalGet("/parent-approval/1/{$submission_id}/{$old_token}");
    $this->assertSession()->pageTextContains('This approval link has expired');
  }

  /**
   * Test GDPR data masking for parents living apart.
   */
  public function testGdprDataMaskingForParentsApart(): void {
    // Submit form with parents_together = 'apart'.
    $submission = WebformSubmission::create([
      'webform_id' => 'parent_request_form',
      'data' => [
        'child_name' => 'Test Barn',
        'child_cpr' => '0101121234',
        'parent1_email' => 'parent1@test.dk',
        'parent2_email' => 'parent2@test.dk',
        'parents_together' => 'apart',
        'request_details' => 'Test request',
        'parent1_status' => 'pending',
        'parent2_status' => 'pending',
      ],
    ]);
    $submission->save();
    $submission_id = $submission->id();

    // Generate token and authenticate.
    $token_service = \Drupal::service('aabenforms_workflows.approval_token');
    $token1 = $token_service->generateToken($submission_id, 1);
    $this->mockMitIdAuthentication($submission_id, 1);

    // Parent 1 views approval page.
    $this->drupalGet("/parent-approval/1/{$submission_id}/{$token1}");

    // Verify CPR is masked (only shows DDMMYY-XXXX, never the last digit).
    $this->assertSession()->pageTextContains('010112-XXXX');
    $this->assertSession()->pageTextNotContains('010112-XXX4');
    $this->assertSession()->pageTextNotContains('0101121234');
    $this->assertSession()->pageTextContains('masked for privacy');
  }

  /**
   * Test that already processed submissions cannot be re-submitted.
   */
  public function testAlreadyProcessedSubmission(): void {
    $submission = $this->createTestSubmission();
    $submission->setElementData('parent1_status', 'complete');
    $submission->save();
    $submission_id = $submission->id();

    // Generate token and authenticate.
    $token_service = \Drupal::service('aabenforms_workflows.approval_token');
    $token1 = $token_service->generateToken($submission_id, 1);
    $this->mockMitIdAuthentication($submission_id, 1);

    // Try to access approval page.
    $this->drupalGet("/parent-approval/1/{$submission_id}/{$token1}");

    // Should see already processed message.
    $this->assertSession()->pageTextContains('You have already approved this request');
    $this->assertSession()->fieldNotExists('action');
  }

  /**
   * Test concurrent parent approvals.
   */
  public function testConcurrentParentApprovals(): void {
    $submission = $this->createTestSubmission();
    $submission_id = $submission->id();

    $token_service = \Drupal::service('aabenforms_workflows.approval_token');

    // Both parents authenticate simultaneously.
    $token1 = $token_service->generateToken($submission_id, 1);
    $token2 = $token_service->generateToken($submission_id, 2);

    $this->mockMitIdAuthentication($submission_id, 1);
    $this->mockMitIdAuthentication($submission_id, 2);

    // Parent 1 accesses and approves.
    $this->drupalGet("/parent-approval/1/{$submission_id}/{$token1}");
    $page = $this->getSession()->getPage();
    $page->selectFieldOption('action', 'approve');
    $page->pressButton('Submit Decision');

    // Parent 2 accesses and approves (should work independently).
    $this->drupalGet("/parent-approval/2/{$submission_id}/{$token2}");
    $page->selectFieldOption('action', 'approve');
    $page->pressButton('Submit Decision');

    // Both should be recorded.
    $submission = \Drupal::entityTypeManager()
      ->getStorage('webform_submission')
      ->load($submission_id);

    $this->assertEquals('complete', $submission->getElementData('parent1_status'));
    $this->assertEquals('complete', $submission->getElementData('parent2_status'));
  }

  /**
   * Mock MitID authentication for testing.
   *
   * @param int $submission_id
   *   The submission ID.
   * @param int $parent_number
   *   The parent number (1 or 2).
   */
  protected function mockMitIdAuthentication(int $submission_id, int $parent_number): void {
    // Set session variables to simulate MitID authentication.
    $session = $this->getSession();
    $session->visit($this->baseUrl . '/session/set/mitid');

    // Execute JavaScript to set session data.
    $this->getSession()->executeScript(
      sprintf(
        'sessionStorage.setItem("mitid_authenticated_parent%d", "1"); sessionStorage.setItem("mitid_cpr", "%s");',
        $parent_number,
        $parent_number == 1 ? '1234567890' : '0987654321'
      )
    );

    // Also set server-side session if controller checks for it.
    $tempstore = \Drupal::service('tempstore.private')->get('aabenforms_workflows');
    $tempstore->set("mitid_authenticated_parent{$parent_number}", TRUE);
    $tempstore->set('mitid_cpr', $parent_number == 1 ? '1234567890' : '0987654321');
  }

  /**
   * Creates a test submission.
   *
   * @return \Drupal\webform\WebformSubmissionInterface
   *   The created submission.
   */
  protected function createTestSubmission() {
    $submission = WebformSubmission::create([
      'webform_id' => 'parent_request_form',
      'data' => [
        'child_name' => 'Test Barn Nielsen',
        'child_cpr' => '0101121234',
        'parent1_email' => 'parent1@test.dk',
        'parent2_email' => 'parent2@test.dk',
        'parents_together' => 'together',
        'request_details' => 'Test daycare enrollment request',
        'parent1_status' => 'pending',
        'parent2_status' => 'pending',
      ],
    ]);
    $submission->save();
    return $submission;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Clean up test data.
    if ($this->webform) {
      $this->webform->delete();
    }

    // Delete all test submissions.
    $submissions = \Drupal::entityTypeManager()
      ->getStorage('webform_submission')
      ->loadByProperties(['webform_id' => 'parent_request_form']);

    foreach ($submissions as $submission) {
      $submission->delete();
    }

    parent::tearDown();
  }

}
