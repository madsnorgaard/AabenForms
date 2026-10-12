<?php

namespace Drupal\Tests\aabenforms_workflows\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aabenforms_workflows\Service\WorkflowTemplateInstantiator;

/**
 * Tests WorkflowTemplateInstantiator end-to-end.
 *
 * Exercises instantiate() against real BPMN templates shipped in the module's
 * workflows/ directory, plus deleteInstance() and getInstances() round-trips.
 * Covers the configuration-validation gate, ECA config emission, BPMN walker,
 * and the wizard-edited XML override path.
 *
 * @group aabenforms_workflows
 * @coversDefaultClass \Drupal\aabenforms_workflows\Service\WorkflowTemplateInstantiator
 */
class WorkflowTemplateInstantiatorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Strict config schema is FALSE because the instantiator emits ECA action
   * configurations whose shape lives in the bpmn_io / modeler_api runtime
   * rather than in a static schema, and emits template_instance metadata
   * fields (modeller, version) sourced from the BPMN file. These pass
   * production runtime validation but trip strict schema checks in the
   * kernel test container, which would only verify shape, not behaviour.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   *
   * ECA 3.1.x hard-deps modeler_api for the modeler_api.template_token_resolver
   * service. bpmn_io is added because instantiate() touches BPMN-aware code
   * paths (XPath traversal of process/serviceTask/sequenceFlow nodes).
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'file',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'webform',
    'modeler_api',
    'bpmn_io',
    'eca',
    'eca_base',
    'eca_content',
    'eca_user',
    'aabenforms_core',
    // aabenforms_workflows.info.yml declares aabenforms_mitid as a hard dep
    // (the parent_cpr_verifier service injects aabenforms_mitid.session_manager
    // for the issue #54 security gate). The kernel container compile fails
    // without this even when the failing test path doesn't hit the verifier.
    'aabenforms_mitid',
    'aabenforms_workflows',
  ];

  /**
   * The instantiator under test.
   *
   * @var \Drupal\aabenforms_workflows\Service\WorkflowTemplateInstantiator
   */
  protected WorkflowTemplateInstantiator $instantiator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
    $this->instantiator = $this->container->get('aabenforms_workflows.template_instantiator');
  }

  /**
   * The instantiator from the current container.
   *
   * Instantiate() ends with drupal_flush_all_caches(), which rebuilds the
   * container, so services fetched in setUp() see stale config caches.
   */
  protected function freshInstantiator(): WorkflowTemplateInstantiator {
    return \Drupal::service('aabenforms_workflows.template_instantiator');
  }

  /**
   * Helper: returns a valid contact_form configuration.
   *
   * @param array $overrides
   *   Keys to overlay on the base config.
   *
   * @return array
   *   Configuration array suitable for instantiate().
   */
  protected function contactFormConfig(array $overrides = []): array {
    // No 'id' here: instantiate() derives the workflow_id internally via
    // generateWorkflowId() and returns it in $result['workflow_id']. Adding
    // 'id' to the configuration array trips configuration.id missing schema
    // because aabenforms_workflows.template_instance.* schema doesn't define
    // an id key inside the inner configuration mapping.
    $base = [
      'label' => 'Kernel test contact form',
      'webform_id' => 'contact_kernel_test',
      'parameters' => [
        'workflow_label' => 'Kernel test contact form',
        'submitter_email_field' => 'email',
        'recipient_email' => 'team@example.test',
        'auto_reply' => 'true',
      ],
      'actions' => [],
      'status' => TRUE,
    ];
    return array_replace_recursive($base, $overrides);
  }

  /**
   * Helper: returns a valid building_permit configuration.
   *
   * @return array
   *   Configuration array suitable for instantiate().
   */
  protected function buildingPermitConfig(): array {
    return [
      'label' => 'Kernel test building permit',
      'webform_id' => 'permit_kernel_test',
      'parameters' => [
        'workflow_label' => 'Kernel test building permit',
        'applicant_email_field' => 'email',
        'cpr_field' => 'cpr',
        'address_field' => 'address',
        'caseworker_email' => 'caseworker@example.test',
      ],
      'actions' => [],
      'status' => TRUE,
    ];
  }

  /**
   * Tests instantiating a simple BPMN template (contact_form).
   *
   * @covers ::instantiate
   * @covers ::generateWorkflowId
   * @covers ::workflowExists
   * @covers ::createTemplateInstanceConfig
   * @covers ::generateEcaWorkflow
   * @covers ::buildEcaEvents
   * @covers ::buildEcaActions
   * @covers ::walkBpmnNode
   * @covers ::actionFromTask
   */
  public function testInstantiateContactForm(): void {
    $config = $this->contactFormConfig();

    $result = $this->instantiator->instantiate('contact_form', $config);

    $this->assertTrue($result['success'], 'instantiate() succeeds for contact_form: ' . ($result['message'] ?? ''));
    $this->assertNotEmpty($result['workflow_id']);
    $this->assertSame([], $result['errors']);

    $workflow_id = $result['workflow_id'];

    // Template instance config is created.
    $instance = $this->config('aabenforms_workflows.template_instance.' . $workflow_id);
    $this->assertFalse($instance->isNew(), 'Template instance config exists.');
    $this->assertSame($workflow_id, $instance->get('id'));
    $this->assertSame('contact_form', $instance->get('template_id'));
    $this->assertSame('contact_kernel_test', $instance->get('webform_id'));
    $this->assertSame('Kernel test contact form', $instance->get('label'));

    // ECA config is emitted with events scoped to the chosen webform bundle.
    $eca = $this->config('eca.eca.' . $workflow_id);
    $this->assertFalse($eca->isNew(), 'ECA config exists.');
    $this->assertSame($workflow_id, $eca->get('id'));
    $this->assertSame('content_entity:insert', $eca->get('events.webform_submit.plugin'));
    $this->assertSame(
      'webform_submission contact_kernel_test',
      $eca->get('events.webform_submit.configuration.type'),
      'Event filters by webform bundle.'
    );

    // The contact_form template wires four service tasks via aabenforms:ecaAction
    // extensions. Walker should emit one action per task plus the start_workflow stub.
    $actions = $eca->get('actions') ?? [];
    $this->assertArrayHasKey('start_workflow', $actions);
    $task_ids = array_filter(array_keys($actions), fn ($id) => str_starts_with($id, 'action_'));
    $this->assertNotEmpty($task_ids, 'BPMN tasks emitted as ECA actions.');

    // ecaAction extension wins over name-based fallback: at least one task
    // resolves to aabenforms_audit_log (declared in the contact_form template).
    $plugins = array_map(fn ($id) => $actions[$id]['plugin'] ?? NULL, $task_ids);
    $this->assertContains(
      'aabenforms_audit_log',
      $plugins,
      'aabenforms:ecaAction extension is honoured for declared tasks.'
    );
  }

  /**
   * Tests instantiating a more complex template with gateways (building_permit).
   *
   * @covers ::instantiate
   * @covers ::buildEcaActions
   * @covers ::walkBpmnNode
   * @covers ::createWorkflowRoutes
   */
  public function testInstantiateBuildingPermit(): void {
    $config = $this->buildingPermitConfig();

    $result = $this->instantiator->instantiate('building_permit', $config);

    $this->assertTrue($result['success'], 'instantiate() succeeds for building_permit: ' . ($result['message'] ?? ''));
    $workflow_id = $result['workflow_id'];

    $eca = $this->config('eca.eca.' . $workflow_id);
    $this->assertFalse($eca->isNew(), 'ECA config exists.');

    // building_permit declares 22 service tasks + 2 user tasks + 4 exclusive
    // gateways. The walker should emit at least a dozen distinct action_ ids,
    // proving the recursion follows gateways and sequence flows.
    $actions = $eca->get('actions') ?? [];
    $task_ids = array_filter(array_keys($actions), fn ($id) => str_starts_with($id, 'action_'));
    $this->assertGreaterThanOrEqual(
      10,
      count($task_ids),
      'Walker emits actions for the bulk of building_permit tasks.'
    );

    // Each emitted action carries a non-empty plugin id - the fallback shape
    // is aabenforms_log so anything else means actionFromTask resolved.
    foreach ($task_ids as $id) {
      $this->assertNotEmpty($actions[$id]['plugin'] ?? '', "Action $id has a plugin id.");
      $this->assertArrayHasKey('successors', $actions[$id]);
    }
  }

  /**
   * An instantiated flow gates its MitID action and denies on failure.
   *
   * @covers ::gateIdentityActions
   */
  public function testInstantiatedFlowGatesIdentity(): void {
    $result = $this->instantiator->instantiate('building_permit', $this->buildingPermitConfig());
    $this->assertTrue($result['success'], $result['message'] ?? '');

    $eca = $this->config('eca.eca.' . $result['workflow_id']);
    $actions = $eca->get('actions') ?? [];
    $conditions = $eca->get('conditions') ?? [];

    $mitid = NULL;
    foreach ($actions as $id => $a) {
      if (($a['plugin'] ?? '') === 'aabenforms_mitid_validate') {
        $mitid = $id;
        break;
      }
    }
    $this->assertNotNull($mitid, 'building_permit instantiates a MitID action.');

    $okId = 'gate_' . $mitid . '_ok';
    $denyId = 'gate_' . $mitid . '_deny';
    $this->assertArrayHasKey($okId, $conditions);
    $this->assertArrayHasKey($denyId, $conditions);
    $this->assertSame('eca_scalar', $conditions[$okId]['plugin']);
    $this->assertFalse((bool) $conditions[$okId]['configuration']['negate']);
    $this->assertTrue((bool) $conditions[$denyId]['configuration']['negate']);

    $okSuccessor = FALSE;
    $denyTarget = NULL;
    foreach ($actions[$mitid]['successors'] as $s) {
      if (($s['condition'] ?? '') === $okId) {
        $okSuccessor = TRUE;
      }
      if (($s['condition'] ?? '') === $denyId) {
        $denyTarget = $s['id'];
      }
    }
    $this->assertTrue($okSuccessor, 'A successor runs only when MitID is verified.');
    $this->assertNotNull($denyTarget, 'A deny branch exists for failed MitID.');
    $this->assertSame('aabenforms_workflow_deny', $actions[$denyTarget]['plugin']);
    $this->assertSame([], $actions[$denyTarget]['successors'], 'Deny is terminal.');

    // No successor anywhere references an undefined condition (no dead labels).
    foreach ($actions as $a) {
      foreach (($a['successors'] ?? []) as $s) {
        $c = $s['condition'] ?? '';
        if ($c !== '') {
          $this->assertArrayHasKey($c, $conditions, "Condition '$c' is defined.");
        }
      }
    }
  }

  /**
   * An annotated exclusive gateway emits a real eca_scalar condition per edge.
   *
   * @covers ::walkBpmnNode
   * @covers ::makeEdgeCondition
   */
  public function testExclusiveGatewayEmitsRealCondition(): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<bpmn:definitions
  xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"
  xmlns:aabenforms="http://aabenforms.dk/bpmn/eca"
  id="Definitions_gw" targetNamespace="http://aabenforms.dk/bpmn">
  <bpmn:process id="gw_process" isExecutable="true">
    <bpmn:startEvent id="StartEvent_1"/>
    <bpmn:serviceTask id="Task_Decide" name="Decide">
      <bpmn:extensionElements>
        <aabenforms:ecaAction plugin="aabenforms_log">
          <aabenforms:config key="message">decide</aabenforms:config>
        </aabenforms:ecaAction>
      </bpmn:extensionElements>
    </bpmn:serviceTask>
    <bpmn:exclusiveGateway id="Gateway_Decide" name="Decision?">
      <bpmn:extensionElements>
        <aabenforms:gateway token="[decision_status]"/>
      </bpmn:extensionElements>
    </bpmn:exclusiveGateway>
    <bpmn:serviceTask id="Task_Approve" name="Approve">
      <bpmn:extensionElements>
        <aabenforms:ecaAction plugin="aabenforms_log">
          <aabenforms:config key="message">a</aabenforms:config>
        </aabenforms:ecaAction>
      </bpmn:extensionElements>
    </bpmn:serviceTask>
    <bpmn:serviceTask id="Task_Reject" name="Reject">
      <bpmn:extensionElements>
        <aabenforms:ecaAction plugin="aabenforms_log">
          <aabenforms:config key="message">r</aabenforms:config>
        </aabenforms:ecaAction>
      </bpmn:extensionElements>
    </bpmn:serviceTask>
    <bpmn:endEvent id="End_A"/>
    <bpmn:endEvent id="End_R"/>
    <bpmn:sequenceFlow id="F1" sourceRef="StartEvent_1" targetRef="Task_Decide"/>
    <bpmn:sequenceFlow id="F2" sourceRef="Task_Decide" targetRef="Gateway_Decide"/>
    <bpmn:sequenceFlow id="F_app" name="approve" sourceRef="Gateway_Decide" targetRef="Task_Approve"/>
    <bpmn:sequenceFlow id="F_rej" name="reject" sourceRef="Gateway_Decide" targetRef="Task_Reject"/>
    <bpmn:sequenceFlow id="F3" sourceRef="Task_Approve" targetRef="End_A"/>
    <bpmn:sequenceFlow id="F4" sourceRef="Task_Reject" targetRef="End_R"/>
  </bpmn:process>
</bpmn:definitions>
XML;

    $result = $this->instantiator->instantiate('contact_form', $this->contactFormConfig(), $xml);
    $this->assertTrue($result['success'], $result['message'] ?? '');

    $eca = $this->config('eca.eca.' . $result['workflow_id']);
    $actions = $eca->get('actions') ?? [];
    $conditions = $eca->get('conditions') ?? [];

    // One eca_scalar condition per labelled edge, comparing the gateway token.
    $scalarRights = [];
    foreach ($conditions as $c) {
      if (($c['plugin'] ?? '') === 'eca_scalar') {
        $this->assertSame('[decision_status]', $c['configuration']['left']);
        $this->assertSame('equal', $c['configuration']['operator']);
        $scalarRights[] = $c['configuration']['right'];
      }
    }
    $this->assertContains('approve', $scalarRights);
    $this->assertContains('reject', $scalarRights);

    // The decide action's successors reference real condition ids, not labels.
    $decide = NULL;
    foreach ($actions as $id => $a) {
      if (str_contains($id, 'Task_Decide')) {
        $decide = $id;
        break;
      }
    }
    $this->assertNotNull($decide);
    $this->assertNotEmpty($actions[$decide]['successors']);
    foreach ($actions[$decide]['successors'] as $s) {
      $this->assertArrayHasKey($s['condition'], $conditions, 'Successor references a real condition, not a raw label.');
    }
  }

  /**
   * Tests that an unknown template id surfaces the validator error path.
   *
   * The metadata service returns no parameters for an unknown id, so the only
   * validator error is the missing webform_id. Pass an empty webform_id to
   * force the validation gate to trip without ever reaching loadTemplate().
   *
   * @covers ::instantiate
   */
  public function testInstantiateUnknownTemplateFailsValidation(): void {
    $result = $this->instantiator->instantiate('this_template_does_not_exist', [
      'label' => 'Phantom',
      'webform_id' => '',
      'parameters' => [],
      'actions' => [],
    ]);

    $this->assertFalse($result['success']);
    $this->assertNull($result['workflow_id']);
    $this->assertNotEmpty($result['errors']);
    $this->assertSame('Configuration validation failed', $result['message']);
  }

  /**
   * Tests that missing required parameters block instantiation.
   *
   * @covers ::instantiate
   */
  public function testInstantiateRejectsMissingRequiredParameters(): void {
    $result = $this->instantiator->instantiate('contact_form', [
      'label' => 'Incomplete',
      'webform_id' => 'incomplete_form',
      'parameters' => [
      // recipient_email + submitter_email_field deliberately omitted.
        'workflow_label' => 'Incomplete',
      ],
      'actions' => [],
    ]);

    $this->assertFalse($result['success']);
    $this->assertNotEmpty($result['errors']);
    $this->assertNull($result['workflow_id']);
  }

  /**
   * Tests that a duplicate logical id is auto-suffixed instead of colliding.
   *
   * The generateWorkflowId() method defends against id collisions by appending
   * _N until workflowExists() returns false. This is the workflowExists branch
   * of the happy path - run instantiate() twice with the same explicit id and
   * verify the second call lands on a different config name.
   *
   * @covers ::generateWorkflowId
   * @covers ::workflowExists
   */
  public function testInstantiateAutoSuffixesDuplicateId(): void {
    $explicit_id = 'duplicate_kernel_test_' . uniqid();
    $first = $this->instantiator->instantiate('contact_form', $this->contactFormConfig([
      'id' => $explicit_id,
    ]));
    $this->assertTrue($first['success'], 'First instantiate() succeeds.');
    $this->assertSame($explicit_id, $first['workflow_id']);

    $second = $this->instantiator->instantiate('contact_form', $this->contactFormConfig([
      'id' => $explicit_id,
    ]));
    $this->assertTrue($second['success'], 'Second instantiate() succeeds with suffix.');
    $this->assertNotSame($explicit_id, $second['workflow_id']);
    $this->assertStringStartsWith($explicit_id . '_', $second['workflow_id']);
  }

  /**
   * Tests the wizard-edited BPMN XML override path.
   *
   * When the wizard step 2 hands over edited XML, generateEcaWorkflow() must
   * parse that XML directly instead of re-reading the on-disk template.
   *
   * @covers ::instantiate
   * @covers ::generateEcaWorkflow
   */
  public function testInstantiateWithBpmnXmlOverride(): void {
    $override_xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<bpmn:definitions
  xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"
  xmlns:aabenforms="http://aabenforms.dk/bpmn/eca"
  id="Definitions_override"
  targetNamespace="http://aabenforms.dk/bpmn">
  <bpmn:process id="override_process" name="Override" isExecutable="true">
    <bpmn:startEvent id="StartEvent_1">
      <bpmn:outgoing>Flow_1</bpmn:outgoing>
    </bpmn:startEvent>
    <bpmn:serviceTask id="Task_Override" name="Override Task">
      <bpmn:extensionElements>
        <aabenforms:ecaAction plugin="aabenforms_log">
          <aabenforms:config key="level">info</aabenforms:config>
          <aabenforms:config key="message">Hello from override</aabenforms:config>
        </aabenforms:ecaAction>
      </bpmn:extensionElements>
      <bpmn:incoming>Flow_1</bpmn:incoming>
      <bpmn:outgoing>Flow_2</bpmn:outgoing>
    </bpmn:serviceTask>
    <bpmn:endEvent id="EndEvent_1">
      <bpmn:incoming>Flow_2</bpmn:incoming>
    </bpmn:endEvent>
    <bpmn:sequenceFlow id="Flow_1" sourceRef="StartEvent_1" targetRef="Task_Override"/>
    <bpmn:sequenceFlow id="Flow_2" sourceRef="Task_Override" targetRef="EndEvent_1"/>
  </bpmn:process>
</bpmn:definitions>
XML;

    $result = $this->instantiator->instantiate(
      'contact_form',
      $this->contactFormConfig(),
      $override_xml,
    );
    $this->assertTrue($result['success'], 'Override path instantiates: ' . ($result['message'] ?? ''));

    $eca = $this->config('eca.eca.' . $result['workflow_id']);
    $actions = $eca->get('actions') ?? [];

    // The override declares exactly one task; the on-disk contact_form has
    // four. Confirm the override won by checking action count.
    $task_ids = array_filter(array_keys($actions), fn ($id) => str_starts_with($id, 'action_'));
    $this->assertCount(1, $task_ids, 'Override XML produced exactly one action.');
    $action_id = reset($task_ids);
    $this->assertSame('aabenforms_log', $actions[$action_id]['plugin']);
    $this->assertSame('info', $actions[$action_id]['configuration']['level'] ?? NULL);
    $this->assertSame('Hello from override', $actions[$action_id]['configuration']['message'] ?? NULL);
  }

  /**
   * Tests that malformed BPMN XML override surfaces an error result.
   *
   * @covers ::instantiate
   * @covers ::generateEcaWorkflow
   */
  public function testInstantiateRejectsMalformedXmlOverride(): void {
    $bad = '<not-valid-xml<<<';
    $result = $this->instantiator->instantiate(
      'contact_form',
      $this->contactFormConfig(),
      $bad,
    );

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('Edited BPMN XML failed to parse', $result['message']);
  }

  /**
   * Tests deleteInstance() removes both config entries.
   *
   * @covers ::deleteInstance
   */
  public function testDeleteInstance(): void {
    $created = $this->instantiator->instantiate('contact_form', $this->contactFormConfig());
    $this->assertTrue($created['success']);
    $workflow_id = $created['workflow_id'];

    // Sanity: configs exist.
    $this->assertFalse($this->config('aabenforms_workflows.template_instance.' . $workflow_id)->isNew());
    $this->assertFalse($this->config('eca.eca.' . $workflow_id)->isNew());

    $deleted = $this->instantiator->deleteInstance($workflow_id);
    $this->assertTrue($deleted, 'deleteInstance() returns TRUE on success.');

    // Both ConfigFactory::config() and ConfigFactory::getEditable() return
    // cached objects; checking via either still reads stale isNew() values
    // after a delete in the same request. Hit the storage backend directly
    // for a fresh existence check.
    $storage = $this->container->get('config.storage');
    $this->assertFalse(
      $storage->exists('aabenforms_workflows.template_instance.' . $workflow_id),
      'template_instance config is removed from storage.',
    );
    $this->assertFalse(
      $storage->exists('eca.eca.' . $workflow_id),
      'eca.eca.<id> is cascaded with the template_instance delete.',
    );
  }

  /**
   * Tests deleteInstance() on a non-existent id returns TRUE without error.
   *
   * The method only deletes when the config isn't new, so a missing id is a
   * silent no-op - the catch block is reserved for actual storage failures.
   *
   * @covers ::deleteInstance
   */
  public function testDeleteInstanceMissingIsNoOp(): void {
    $this->assertTrue($this->instantiator->deleteInstance('does_not_exist_anywhere'));
  }

  /**
   * Tests getInstances() lists all created instances.
   *
   * @covers ::getInstances
   */
  public function testGetInstances(): void {
    $this->assertSame([], $this->instantiator->getInstances(), 'No instances initially.');

    $a = $this->instantiator->instantiate('contact_form', $this->contactFormConfig());
    $b = $this->instantiator->instantiate('contact_form', $this->contactFormConfig());
    $this->assertTrue($a['success']);
    $this->assertTrue($b['success']);

    // instantiate() flushes all caches, which rebuilds the container.
    $instances = $this->freshInstantiator()->getInstances();
    $this->assertCount(2, $instances, 'Two instances listed.');

    $ids = array_map(fn ($row) => $row['id'] ?? NULL, $instances);
    $this->assertContains($a['workflow_id'], $ids);
    $this->assertContains($b['workflow_id'], $ids);

    // Each row carries the structural keys set by createTemplateInstanceConfig.
    foreach ($instances as $row) {
      $this->assertArrayHasKey('id', $row);
      $this->assertArrayHasKey('label', $row);
      $this->assertArrayHasKey('template_id', $row);
      $this->assertArrayHasKey('webform_id', $row);
      $this->assertArrayHasKey('configuration', $row);
      $this->assertArrayHasKey('status', $row);
      $this->assertArrayHasKey('created', $row);
      $this->assertArrayHasKey('updated', $row);
    }
  }

  /**
   * Deleting the flow outside the wizard removes its instance (#199).
   *
   * @covers ::getInstances
   */
  public function testDeletingFlowDirectlyRemovesInstance(): void {
    $result = $this->instantiator->instantiate('contact_form', $this->contactFormConfig());
    $this->assertTrue($result['success']);
    $id = $result['workflow_id'];
    $this->assertCount(1, $this->freshInstantiator()->getInstances());

    \Drupal::entityTypeManager()->getStorage('eca')->load($id)->delete();

    $this->assertTrue(\Drupal::config('aabenforms_workflows.template_instance.' . $id)->isNew(), 'Instance config deleted.');
    $this->assertSame([], $this->freshInstantiator()->getInstances());
  }

  /**
   * An instance whose flow is gone is never listed (#199).
   *
   * @covers ::getInstances
   */
  public function testOrphanedInstanceIsSkipped(): void {
    $result = $this->instantiator->instantiate('contact_form', $this->contactFormConfig());
    $this->assertTrue($result['success']);
    // A flow removed without entity hooks, as a config sync can leave it.
    \Drupal::configFactory()->getEditable('eca.eca.' . $result['workflow_id'])->delete();

    $this->assertFalse(\Drupal::config('aabenforms_workflows.template_instance.' . $result['workflow_id'])->isNew(), 'Instance config remains.');
    $this->assertSame([], $this->freshInstantiator()->getInstances());
  }

  /**
   * Tests generateEmailTemplates() captures action subject/body pairs.
   *
   * @covers ::generateEmailTemplates
   */
  public function testGenerateEmailTemplatesPersistsActionContent(): void {
    $config = $this->contactFormConfig([
      'actions' => [
        'send_confirmation' => [
          'subject' => 'Thanks for contacting us',
          'body' => 'We have received your inquiry.',
          'recipient' => 'submitter@example.test',
        ],
        // No subject/body here, must be skipped.
        'log_only' => [
          'recipient' => 'audit@example.test',
        ],
      ],
    ]);

    $result = $this->instantiator->instantiate('contact_form', $config);
    $this->assertTrue($result['success'], 'instantiate() succeeds: ' . ($result['message'] ?? ''));

    $instance = $this->config('aabenforms_workflows.template_instance.' . $result['workflow_id']);
    $email_templates = $instance->get('email_templates') ?? [];

    $this->assertArrayHasKey('send_confirmation', $email_templates);
    $this->assertSame('Thanks for contacting us', $email_templates['send_confirmation']['subject']);
    $this->assertSame('We have received your inquiry.', $email_templates['send_confirmation']['body']);
    $this->assertSame('submitter@example.test', $email_templates['send_confirmation']['recipient']);

    $this->assertArrayNotHasKey(
      'log_only',
      $email_templates,
      'Actions without subject+body are skipped.'
    );
  }

}
