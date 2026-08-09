<?php

namespace Drupal\aabenforms_workflows\Form;

use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\aabenforms_workflows\Service\BpmnTemplateManager;
use Drupal\aabenforms_workflows\Service\FlowGraphRenderer;
use Drupal\aabenforms_workflows\Service\WorkflowTemplateMetadata;
use Drupal\aabenforms_workflows\Service\WorkflowTemplateInstantiator;
use Drupal\webform\Entity\Webform;
use Drupal\modeler_api\Api as ModelerApi;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Multi-step wizard form for creating workflows from templates.
 *
 * This user-friendly wizard guides municipality staff through:
 * 1. Template selection
 * 2. Visual workflow editor (BPMN.io)
 * 3. Webform configuration
 * 4. Action configuration
 * 5. Data visibility settings (if applicable)
 * 6. Preview and activation.
 */
class WorkflowTemplateWizardForm extends FormBase {

  /**
   * The BPMN template manager service.
   *
   * @var \Drupal\aabenforms_workflows\Service\BpmnTemplateManager
   */
  protected BpmnTemplateManager $templateManager;

  /**
   * The template metadata service.
   *
   * @var \Drupal\aabenforms_workflows\Service\WorkflowTemplateMetadata
   */
  protected WorkflowTemplateMetadata $templateMetadata;

  /**
   * The template instantiator service.
   *
   * @var \Drupal\aabenforms_workflows\Service\WorkflowTemplateInstantiator
   */
  protected WorkflowTemplateInstantiator $instantiator;

  /**
   * The Modeler API service.
   *
   * @var \Drupal\modeler_api\Api
   */
  protected ModelerApi $modelerApi;

  /**
   * The flow graph renderer.
   *
   * @var \Drupal\aabenforms_workflows\Service\FlowGraphRenderer
   */
  protected FlowGraphRenderer $graphRenderer;

  /**
   * Constructs a WorkflowTemplateWizardForm object.
   *
   * @param \Drupal\aabenforms_workflows\Service\BpmnTemplateManager $template_manager
   *   The BPMN template manager service.
   * @param \Drupal\aabenforms_workflows\Service\WorkflowTemplateMetadata $template_metadata
   *   The template metadata service.
   * @param \Drupal\aabenforms_workflows\Service\WorkflowTemplateInstantiator $instantiator
   *   The template instantiator service.
   * @param \Drupal\modeler_api\Api $modeler_api
   *   The Modeler API service.
   * @param \Drupal\aabenforms_workflows\Service\FlowGraphRenderer $graph_renderer
   *   The flow graph renderer.
   */
  public function __construct(
    BpmnTemplateManager $template_manager,
    WorkflowTemplateMetadata $template_metadata,
    WorkflowTemplateInstantiator $instantiator,
    ModelerApi $modeler_api,
    FlowGraphRenderer $graph_renderer,
  ) {
    $this->templateManager = $template_manager;
    $this->templateMetadata = $template_metadata;
    $this->instantiator = $instantiator;
    $this->modelerApi = $modeler_api;
    $this->graphRenderer = $graph_renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('aabenforms_workflows.bpmn_template_manager'),
      $container->get('aabenforms_workflows.template_metadata'),
      $container->get('aabenforms_workflows.template_instantiator'),
      $container->get('modeler_api.service'),
      $container->get('aabenforms_workflows.flow_graph_renderer')
    );
  }

  /**
   * Builds a read-only node-graph render element for a template's ECA flow.
   *
   * @param string|null $template_id
   *   The template id; its flow is "<template_id>_flow".
   *
   * @return array|null
   *   A render element, or NULL when no flow/graph is available.
   */
  protected function graphPreview(?string $template_id): ?array {
    if (!is_string($template_id) || $template_id === '') {
      return NULL;
    }
    $svg = $this->graphRenderer->renderForFlow($template_id . '_flow');
    if ($svg === NULL) {
      return NULL;
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['aabenforms-flow-graph', 'aabenforms-wizard-graph']],
      'svg' => [
        '#markup' => $svg,
        '#allowed_tags' => FlowGraphRenderer::allowedTags(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'aabenforms_workflows_template_wizard_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Initialize step if not set.
    $step = $form_state->get('step') ?? 1;
    $form_state->set('step', $step);

    // Restore values captured on prior steps so per-step #default_value
    // lookups and submitForm() find them via getValue(). Drupal does not
    // repopulate values for form elements that are no longer in the current
    // step's tree, so without this restore webform_id, parameters, action
    // config, and visibility_mode would all be NULL by the time the user
    // reaches step 6. See ::captureWizardData() and ::nextStep().
    $wizard_data = $form_state->get('wizard_data') ?? [];
    foreach ($wizard_data as $key => $value) {
      if ($form_state->getValue($key) === NULL) {
        $form_state->setValue($key, $value);
      }
    }

    // The template browser links into the wizard as
    // /admin/aabenforms/workflow-templates/wizard?template_id=<id>. On that
    // first GET, form_state has no submitted values yet - the query string
    // is the only source of the template_id.
    if ($form_state->getValue('template_id') === NULL) {
      $query_template = \Drupal::request()->query->get('template_id');
      if (is_string($query_template) && $query_template !== '') {
        $form_state->setValue('template_id', $query_template);
      }
    }

    // Add wizard navigation.
    $form['#attached']['library'][] = 'system/admin';
    $form['#attached']['library'][] = 'aabenforms_workflows/workflow_wizard';
    $form['#attributes']['class'][] = 'workflow-wizard-form';

    // Add progress indicator.
    $form['progress'] = [
      '#theme' => 'item_list',
      '#items' => $this->getProgressSteps($step),
      '#attributes' => ['class' => ['wizard-progress']],
    ];

    // Build step-specific form.
    switch ($step) {
      case 1:
        $this->buildStepSelectTemplate($form, $form_state);
        break;

      case 2:
        $this->buildStepVisualEditor($form, $form_state);
        break;

      case 3:
        $this->buildStepConfigureWebform($form, $form_state);
        break;

      case 4:
        $this->buildStepConfigureActions($form, $form_state);
        break;

      case 5:
        $this->buildStepDataVisibility($form, $form_state);
        break;

      case 6:
        $this->buildStepPreviewActivate($form, $form_state);
        break;
    }

    // Add navigation buttons.
    $this->addNavigationButtons($form, $form_state, $step);

    return $form;
  }

  /**
   * Builds Step 1: Select Template.
   */
  protected function buildStepSelectTemplate(array &$form, FormStateInterface $form_state): void {
    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Step 1: Select a Workflow Template') . '</h2>',
    ];

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Choose a pre-built workflow template that matches your needs. Each template is designed for common municipality use cases.') . '</p>',
    ];

    $templates = $this->templateManager->getAvailableTemplates();

    $options = [];
    $descriptions = [];

    foreach ($templates as $template_id => $template) {
      $options[$template_id] = '<strong>' . $template['name'] . '</strong>';
      $clean_description = preg_replace('/\[category:[^\]]+\]/', '', $template['description']);
      $descriptions[$template_id] = trim($clean_description);
    }

    $form['template_id'] = [
      '#type' => 'radios',
      '#title' => $this->t('Available Templates'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('template_id'),
    ];

    // Add descriptions for each template.
    foreach ($descriptions as $template_id => $description) {
      $form['template_id'][$template_id]['#description'] = $description;
    }

    // Preview section.
    if ($selected_template = $form_state->getValue('template_id')) {
      $preview = $this->templateMetadata->getTemplatePreview($selected_template);

      $form['preview'] = [
        '#type' => 'details',
        '#title' => $this->t('Template Preview'),
        '#open' => TRUE,
      ];

      $form['preview']['description'] = [
        '#markup' => '<p>' . $preview['description'] . '</p>',
      ];

      // Visual node-graph of the template's flow (start -> gated steps -> deny).
      if ($graph = $this->graphPreview($selected_template)) {
        $form['preview']['graph'] = $graph;
      }

      if (!empty($preview['steps'])) {
        $steps_list = [];
        foreach ($preview['steps'] as $step) {
          $steps_list[] = $this->t('Step @num: @name', [
            '@num' => $step['step'],
            '@name' => $step['name'],
          ]);
        }

        $form['preview']['steps'] = [
          '#theme' => 'item_list',
          '#title' => $this->t('Workflow Steps'),
          '#items' => $steps_list,
        ];
      }
    }
  }

  /**
   * Builds Step 2: Flow Preview (read-only node-graph of the template's flow).
   */
  protected function buildStepVisualEditor(array &$form, FormStateInterface $form_state): void {
    $template_id = $form_state->getValue('template_id');

    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Step 2: Flow Preview') . '</h2>',
    ];

    $form['help'] = [
      '#markup' => '<p>' . $this->t('This is your workflow as it will run - generated from the live ECA flow. Branch labels show the gating condition (for example MitID = verified).') . '</p>',
    ];

    // Read-only node-graph preview of the flow - the same diagram shown on the
    // template browser and the dashboard.
    if ($graph = $this->graphPreview($template_id)) {
      $form['flow_graph'] = $graph;
    }

    // Load template BPMN XML.
    $bpmn_xml = $form_state->get('bpmn_xml');
    if (!$bpmn_xml) {
      $bpmn_xml = $this->templateManager->loadTemplate($template_id, TRUE);
      $form_state->set('bpmn_xml', $bpmn_xml);
    }

    // Carry the template BPMN through for instantiation. The accurate flow
    // preview is the read-only node-graph above; in-place visual EDITING is
    // handled by the React Flow editor (separate increment). The old bpmn-js
    // canvas was removed - it rendered an empty, broken editor below the graph.
    $form['bpmn_xml'] = [
      '#type' => 'hidden',
      '#default_value' => $bpmn_xml,
      '#attributes' => ['id' => 'bpmn-xml-data'],
    ];
  }

  /**
   * Builds Step 3: Configure Webform.
   */
  protected function buildStepConfigureWebform(array &$form, FormStateInterface $form_state): void {
    $template_id = $form_state->getValue('template_id');

    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Step 3: Configure Webform Integration') . '</h2>',
    ];

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Connect this workflow to a webform and map the form fields to workflow variables.') . '</p>',
    ];

    // Webform selection.
    $webforms = Webform::loadMultiple();
    $webform_options = [];
    foreach ($webforms as $webform_id => $webform) {
      $webform_options[$webform_id] = $webform->label();
    }

    $form['webform_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Webform'),
      '#description' => $this->t('Which webform should trigger this workflow?'),
      '#options' => $webform_options,
      '#required' => TRUE,
      // An explicit empty default: without it the first webform
      // (alphabetically) is silently preselected, and a flow can go live
      // bound to the wrong form without the admin ever touching the field
      // (#197 - three such flows were found on production).
      '#empty_option' => $this->t('- Select webform -'),
      '#empty_value' => '',
      '#default_value' => $form_state->getValue('webform_id'),
      '#ajax' => [
        'callback' => '::updateFieldMappings',
        'wrapper' => 'field-mappings-wrapper',
        'event' => 'change',
      ],
    ];

    // Field mappings container.
    $form['field_mappings'] = [
      '#type' => 'container',
      '#prefix' => '<div id="field-mappings-wrapper">',
      '#suffix' => '</div>',
    ];

    if ($webform_id = $form_state->getValue('webform_id')) {
      $webform = Webform::load($webform_id);
      $webform_fields = $webform ? $webform->getElementsDecodedAndFlattened() : [];
      $field_options = [];

      foreach ($webform_fields as $field_id => $field) {
        $field_options[$field_id] = $field['#title'] ?? $field_id;
      }

      // Get template parameters that need field mapping.
      $parameters = $this->templateMetadata->getTemplateParameters($template_id);

      foreach ($parameters as $param_id => $param) {
        if ($param['type'] === 'webform_field') {
          $form['field_mappings'][$param_id] = [
            '#type' => 'select',
            '#title' => $param['label'],
            '#description' => $param['description'],
            '#options' => $field_options,
            '#required' => $param['required'],
            '#default_value' => $form_state->getValue($param_id) ?? $param['default'],
          ];
        }
      }
    }
    else {
      $form['field_mappings']['message'] = [
        '#markup' => '<p>' . $this->t('Select a webform to configure field mappings.') . '</p>',
      ];
    }
  }

  /**
   * Builds Step 3: Configure Actions.
   */
  protected function buildStepConfigureActions(array &$form, FormStateInterface $form_state): void {
    $template_id = $form_state->getValue('template_id');

    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Step 4: Configure Actions') . '</h2>',
    ];

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Customize email templates, approval pages, and notification settings for each workflow action.') . '</p>',
    ];

    // Get template parameters (non-field mappings).
    $parameters = $this->templateMetadata->getTemplateParameters($template_id);

    $form['parameters'] = [
      '#type' => 'details',
      '#title' => $this->t('Workflow Settings'),
      '#open' => TRUE,
    ];

    foreach ($parameters as $param_id => $param) {
      if ($param['type'] !== 'webform_field') {
        $element = $this->buildParameterElement($param, $form_state);
        $form['parameters'][$param_id] = $element;
      }
    }

    // Get configurable actions.
    $actions = $this->templateMetadata->getConfigurableActions($template_id);

    if (!empty($actions)) {
      // Keyed as 'action_config' rather than 'actions' because
      // addNavigationButtons() owns $form['actions'] for the Back/Next/Cancel
      // buttons and would otherwise overwrite this whole config tree.
      $form['action_config'] = [
        '#type' => 'details',
        '#title' => $this->t('Action Configuration'),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];

      foreach ($actions as $action_id => $action) {
        // A collapsed details hiding a required field means the admin sees a
        // near-empty step, clicks Next, and gets errors for fields that were
        // never visible (#197). Open every action that requires input.
        $has_required = FALSE;
        foreach ($action['configurable_fields'] as $field) {
          if (!empty($field['required'])) {
            $has_required = TRUE;
            break;
          }
        }
        $form['action_config'][$action_id] = [
          '#type' => 'details',
          '#title' => $action['name'],
          '#open' => $has_required,
        ];

        foreach ($action['configurable_fields'] as $field_id => $field) {
          $form['action_config'][$action_id][$field_id] = [
            '#type' => $field['type'] === 'textarea' ? 'textarea' : 'textfield',
            '#title' => $field['label'],
            '#required' => $field['required'],
            '#default_value' => $form_state->getValue(['action_config', $action_id, $field_id]),
          ];

          if ($field['type'] === 'textarea') {
            $form['action_config'][$action_id][$field_id]['#rows'] = 5;
          }
        }
      }
    }
  }

  /**
   * Builds Step 4: Data Visibility.
   */
  protected function buildStepDataVisibility(array &$form, FormStateInterface $form_state): void {
    $template_id = $form_state->getValue('template_id');

    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Step 5: Data Visibility (Optional)') . '</h2>',
    ];

    // Only show for templates with multi-party workflows.
    $multi_party_templates = ['building_permit', 'foi_request'];

    if (!in_array($template_id, $multi_party_templates)) {
      $form['not_applicable'] = [
        '#markup' => '<p>' . $this->t('This template does not require data visibility configuration.') . '</p>',
      ];
      return;
    }

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Configure which data is visible to each party involved in the workflow. This ensures GDPR compliance.') . '</p>',
    ];

    $form['visibility_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Visibility Mode'),
      '#options' => [
        'full' => $this->t('Full transparency - All parties see all data'),
        'restricted' => $this->t('Restricted - Each party sees only relevant data'),
      ],
      '#default_value' => $form_state->getValue('visibility_mode') ?? 'restricted',
      '#required' => TRUE,
    ];

    $form['visibility_note'] = [
      '#markup' => '<div class="messages messages--warning">' . $this->t('Note: CPR numbers and other sensitive data are always encrypted per GDPR requirements.') . '</div>',
    ];
  }

  /**
   * Builds Step 5: Preview and Activate.
   */
  protected function buildStepPreviewActivate(array &$form, FormStateInterface $form_state): void {
    $template_id = $form_state->getValue('template_id');
    $templates = $this->templateManager->getAvailableTemplates();
    $template = $templates[$template_id];

    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Step 6: Preview and Activate') . '</h2>',
    ];

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Review your workflow configuration and activate it.') . '</p>',
    ];

    // Workflow name.
    $form['workflow_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Workflow Name'),
      '#description' => $this->t('Give this workflow a descriptive name'),
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('workflow_label') ?? $template['name'],
    ];

    // Summary.
    $form['summary'] = [
      '#type' => 'details',
      '#title' => $this->t('Configuration Summary'),
      '#open' => TRUE,
    ];

    $webform_id = $form_state->getValue('webform_id');
    $webform = $webform_id ? Webform::load($webform_id) : NULL;

    $summary_items = [
      $this->t('<strong>Template:</strong> @name', ['@name' => $template['name']]),
      $this->t('<strong>Webform:</strong> @form', ['@form' => $webform ? $webform->label() : 'N/A']),
    ];

    $form['summary']['items'] = [
      '#theme' => 'item_list',
      '#items' => $summary_items,
    ];

    // Preview diagram.
    $preview = $this->templateMetadata->getTemplatePreview($template_id);

    if (!empty($preview['steps'])) {
      $form['workflow_steps'] = [
        '#type' => 'details',
        '#title' => $this->t('Workflow Steps'),
        '#open' => TRUE,
      ];

      $steps_list = [];
      foreach ($preview['steps'] as $step) {
        $steps_list[] = $this->t('@num. @name', [
          '@num' => $step['step'],
          '@name' => $step['name'],
        ]);
      }

      $form['workflow_steps']['list'] = [
        '#theme' => 'item_list',
        '#items' => $steps_list,
      ];
    }

    // Status.
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activate workflow immediately'),
      '#description' => $this->t('If unchecked, workflow will be created but not active.'),
      '#default_value' => TRUE,
    ];
  }

  /**
   * Builds a form element for a parameter.
   */
  protected function buildParameterElement(array $param, FormStateInterface $form_state): array {
    $element = [];

    switch ($param['type']) {
      case 'integer':
        $element = [
          '#type' => 'number',
          '#title' => $param['label'],
          '#description' => $param['description'],
          '#required' => $param['required'],
          '#default_value' => $form_state->getValue($param['id']) ?? $param['default'],
        ];
        break;

      case 'boolean':
        $element = [
          '#type' => 'checkbox',
          '#title' => $param['label'],
          '#description' => $param['description'],
          '#default_value' => $form_state->getValue($param['id']) ?? ($param['default'] === 'true'),
        ];
        break;

      case 'textarea':
        $element = [
          '#type' => 'textarea',
          '#title' => $param['label'],
          '#description' => $param['description'],
          '#required' => $param['required'],
          '#default_value' => $form_state->getValue($param['id']) ?? $param['default'],
          '#rows' => 4,
        ];
        break;

      case 'email':
        $element = [
          '#type' => 'email',
          '#title' => $param['label'],
          '#description' => $param['description'],
          '#required' => $param['required'],
          '#default_value' => $form_state->getValue($param['id']) ?? $param['default'],
        ];
        break;

      default:
        $element = [
          '#type' => 'textfield',
          '#title' => $param['label'],
          '#description' => $param['description'],
          '#required' => $param['required'],
          '#default_value' => $form_state->getValue($param['id']) ?? $param['default'],
        ];
        break;
    }

    return $element;
  }

  /**
   * Gets progress step labels.
   */
  protected function getProgressSteps(int $current_step): array {
    $steps = [
      1 => $this->t('Select Template'),
      2 => $this->t('Flow Preview'),
      3 => $this->t('Configure Webform'),
      4 => $this->t('Configure Actions'),
      5 => $this->t('Data Visibility'),
      6 => $this->t('Preview & Activate'),
    ];

    $items = [];
    foreach ($steps as $step_num => $label) {
      $class = $step_num === $current_step ? 'current' : ($step_num < $current_step ? 'completed' : 'pending');
      // Drop the class on the <li> directly. Putting raw HTML strings in
      // #items would escape them; use #wrapper_attributes so item_list
      // renders the class on the list element itself.
      $items[] = [
        '#markup' => $label,
        '#wrapper_attributes' => ['class' => ['wizard-step', $class]],
      ];
    }

    return $items;
  }

  /**
   * Adds navigation buttons to form.
   */
  protected function addNavigationButtons(array &$form, FormStateInterface $form_state, int $step): void {
    $form['actions'] = ['#type' => 'actions'];

    if ($step > 1) {
      $form['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::previousStep'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['button']],
      ];
    }

    if ($step < 6) {
      $form['actions']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Next'),
        '#submit' => ['::nextStep'],
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }
    else {
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Create Workflow'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('aabenforms_workflows.template_browser'),
      '#attributes' => ['class' => ['button']],
    ];
  }

  /**
   * AJAX callback for field mappings.
   */
  public function updateFieldMappings(array &$form, FormStateInterface $form_state) {
    return $form['field_mappings'];
  }

  /**
   * AJAX callback for autosaving BPMN XML.
   */
  public function autosaveBpmn(array &$form, FormStateInterface $form_state) {
    $bpmn_xml = $form_state->getValue('bpmn_xml');
    $form_state->set('bpmn_xml', $bpmn_xml);

    // Return success response.
    return [
      '#type' => 'ajax',
      '#commands' => [
        [
          'command' => 'invoke',
          'method' => 'trigger',
          'selector' => '#bpmn-validation-status',
          'arguments' => ['bpmn:autosaved'],
        ],
      ],
    ];
  }

  /**
   * AJAX callback for validating BPMN XML.
   */
  public function validateBpmn(array &$form, FormStateInterface $form_state) {
    $bpmn_xml = $form_state->getValue('bpmn_xml');

    // Validate using BpmnTemplateManager.
    $is_valid = $this->templateManager->validateTemplate($bpmn_xml);
    $errors = $is_valid ? [] : $this->templateManager->getValidationErrors();

    $response = [
      'valid' => $is_valid,
      'errors' => $errors,
    ];

    return [
      '#type' => 'ajax',
      '#commands' => [
        [
          'command' => 'invoke',
          'method' => 'trigger',
          'selector' => '#bpmn-validation-status',
          'arguments' => ['bpmn:validated', [$response]],
        ],
      ],
    ];
  }

  /**
   * Submit handler for next step.
   */
  public function nextStep(array &$form, FormStateInterface $form_state): void {
    $step = $form_state->get('step');

    // Snapshot this step's submitted values so they survive future rebuilds
    // when the elements are no longer in the form tree.
    $this->captureWizardData($form_state);

    // Mirror BPMN XML into dedicated storage for the AJAX autosave path.
    if ($step === 2) {
      $form_state->set('bpmn_xml', $form_state->getValue('bpmn_xml'));
    }

    $form_state->set('step', $step + 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Captures submitted wizard values into form_state storage.
   *
   * Drupal multi-step forms re-populate $form_state->getValue() only for
   * elements in the current step's form tree. Everything the user entered
   * on earlier steps would otherwise be lost by the time submitForm() runs.
   * This helper snapshots the current values under a single 'wizard_data'
   * storage key, which buildForm() merges back into form_state on each
   * subsequent render.
   */
  protected function captureWizardData(FormStateInterface $form_state): void {
    $skip = ['op', 'form_id', 'form_build_id', 'form_token', 'submit', 'next', 'back', 'cancel'];
    $data = $form_state->get('wizard_data') ?? [];
    foreach ($form_state->getValues() as $key => $value) {
      if (in_array($key, $skip, TRUE)) {
        continue;
      }
      $data[$key] = $value;
    }
    $form_state->set('wizard_data', $data);
  }

  /**
   * Submit handler for previous step.
   */
  public function previousStep(array &$form, FormStateInterface $form_state): void {
    $step = $form_state->get('step');
    $form_state->set('step', $step - 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Fold step 6's fresh input into wizard_data and then read everything
    // from storage. We cannot trust $form_state->getValue() here: Drupal's
    // FormBuilder::processForm() calls $form_state->setValues([]) before
    // running doBuildForm(), which only repopulates values for elements in
    // the current step's tree. So webform_id, parameters, action_config and
    // visibility_mode are all NULL via getValue() at this point, even though
    // buildForm() restored them in time for rendering the summary.
    $this->captureWizardData($form_state);
    $data = $form_state->get('wizard_data') ?? [];

    $template_id = $data['template_id']
      ?? $form_state->getValue('template_id')
      ?? \Drupal::request()->query->get('template_id');
    if (!is_string($template_id) || $template_id === '') {
      $this->messenger()->addError($this->t('Could not determine which template to instantiate. Please start the wizard again from the template browser.'));
      $form_state->setRedirect('aabenforms_workflows.template_browser');
      return;
    }

    // Build parameters array from wizard_data.
    $parameters = [];
    $template_params = $this->templateMetadata->getTemplateParameters($template_id);
    foreach ($template_params as $param_id => $param) {
      if (array_key_exists($param_id, $data) && $data[$param_id] !== NULL) {
        $parameters[$param_id] = $data[$param_id];
      }
    }

    // Build configuration array. action_config holds the per-action tree
    // captured on step 4 (keyed that way to avoid colliding with the nav
    // button group); the instantiator still expects the key 'actions'.
    $configuration = [
      'label' => $data['workflow_label'] ?? NULL,
      'webform_id' => $data['webform_id'] ?? NULL,
      'parameters' => $parameters,
      'actions' => $data['action_config'] ?? [],
      'visibility_mode' => $data['visibility_mode'] ?? NULL,
      'status' => $data['status'] ?? TRUE,
    ];

    // Instantiate workflow. Pass the BPMN XML last edited in step 2 so
    // visual edits survive the round-trip to ECA config; without this the
    // instantiator re-reads the on-disk template and ignores them.
    $edited_xml = $form_state->get('bpmn_xml')
      ?: ($data['bpmn_xml'] ?? NULL);
    $result = $this->instantiator->instantiate(
      $template_id,
      $configuration,
      is_string($edited_xml) && $edited_xml !== '' ? $edited_xml : NULL,
    );

    if ($result['success']) {
      $this->messenger()->addStatus(
        $this->t('Workflow "@label" has been created successfully!', [
          '@label' => $configuration['label'],
        ])
      );

      // Redirect to template browser.
      $form_state->setRedirect('aabenforms_workflows.template_browser');
    }
    else {
      $this->messenger()->addError(
        $this->t('Failed to create workflow: @message', [
          '@message' => $result['message'],
        ])
      );

      foreach ($result['errors'] as $error) {
        $this->messenger()->addError($error);
      }

      // Keep the user on step 6 with their entered data so they can fix the
      // reported issues instead of being booted back to step 1 empty-handed.
      $form_state->set('step', 6);
      $form_state->setRebuild(TRUE);
    }
  }

}
