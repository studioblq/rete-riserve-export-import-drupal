<?php

namespace Drupal\site_json_export\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Site JSON Export.
 */
class SiteJsonExportSettingsForm extends ConfigFormBase {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The route builder.
   *
   * @var \Drupal\Core\Routing\RouteBuilderInterface
   */
  protected $routeBuilder;

  /**
   * Creates a new settings form.
   */
  public function __construct(
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    LanguageManagerInterface $language_manager,
    RouteBuilderInterface $route_builder
  ) {
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager = $language_manager;
    $this->routeBuilder = $route_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('router.builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'site_json_export_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['site_json_export.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('site_json_export.settings');

    $node_type_storage = $this->entityTypeManager->getStorage('node_type');
    $node_types = $node_type_storage->loadMultiple();

    $node_type_options = [];
    foreach ($node_types as $node_type) {
      $node_type_options[$node_type->id()] = $node_type->label();
    }

    $language_options = ['all' => $this->t('All available translations')];
    foreach ($this->languageManager->getLanguages() as $language) {
      $language_options[$language->getId()] = $language->getName();
    }

    $form['endpoint_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('JSON endpoint path'),
      '#description' => $this->t('Example: /my-export. Must start with /.') ,
      '#default_value' => $config->get('endpoint_path') ?: '/site-json-export',
      '#required' => TRUE,
    ];

    $form['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Export language'),
      '#description' => $this->t('Choose one language for export, or include all translations.'),
      '#options' => $language_options,
      '#default_value' => $config->get('language') ?: 'all',
      '#required' => TRUE,
    ];

    $form['node_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types to export'),
      '#description' => $this->t('Select the node types that should be available in JSON output.'),
      '#options' => $node_type_options,
      '#default_value' => $config->get('node_types') ?: [],
    ];

    $saved_fields = (array) ($config->get('fields') ?: []);
    $saved_entity_reference_modes = (array) ($config->get('entity_reference_modes') ?: []);

    $form['fields_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Fields to export'),
      '#description' => $this->t('Select which fields should be exported for each content type.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    foreach ($node_types as $bundle => $node_type) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
      $field_options = [];

      foreach ($field_definitions as $field_name => $field_definition) {
        if (!$this->isExportableField($field_name, $field_definition)) {
          continue;
        }

        $field_options[$field_name] = $this->t('@label (@machine_name)', [
          '@label' => $field_definition->getLabel(),
          '@machine_name' => $field_name,
        ]);
      }

      $default_fields = $saved_fields[$bundle] ?? [];

      $form['fields_wrapper'][$bundle] = [
        '#type' => 'checkboxes',
        '#title' => $node_type->label(),
        '#options' => $field_options,
        '#default_value' => $default_fields,
        '#states' => [
          'visible' => [
            ':input[name="node_types[' . $bundle . ']"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $entity_reference_fields = [];
      foreach ($field_definitions as $field_name => $field_definition) {
        if (!$this->isExportableField($field_name, $field_definition)) {
          continue;
        }
        if ($field_definition->getType() !== 'entity_reference') {
          continue;
        }

        $entity_reference_fields[$field_name] = $this->t('@label (@machine_name)', [
          '@label' => $field_definition->getLabel(),
          '@machine_name' => $field_name,
        ]);
      }

      if ($entity_reference_fields !== []) {
        $form['fields_wrapper'][$bundle . '_entity_ref_modes'] = [
          '#type' => 'details',
          '#title' => $this->t('Entity reference output for @type', ['@type' => $node_type->label()]),
          '#open' => FALSE,
          '#states' => [
            'visible' => [
              ':input[name="node_types[' . $bundle . ']"]' => ['checked' => TRUE],
            ],
          ],
        ];

        foreach ($entity_reference_fields as $field_name => $field_label) {
          $mode_default = isset($saved_entity_reference_modes[$bundle][$field_name])
            ? (string) $saved_entity_reference_modes[$bundle][$field_name]
            : 'id';
          if (!in_array($mode_default, ['id', 'label', 'id_label'], TRUE)) {
            $mode_default = 'id';
          }

          $form['fields_wrapper'][$bundle . '_entity_ref_modes']['entity_reference_modes'][$bundle][$field_name] = [
            '#type' => 'select',
            '#title' => $field_label,
            '#options' => [
              'id' => $this->t('ID (target_id)'),
              'label' => $this->t('Valore entita (label)'),
              'id_label' => $this->t('ID + valore entita'),
            ],
            '#default_value' => $mode_default,
            '#states' => [
              'visible' => [
                ':input[name="fields_wrapper[' . $bundle . '][' . $field_name . ']"]' => ['checked' => TRUE],
              ],
            ],
          ];
        }
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $endpoint_path = trim((string) $form_state->getValue('endpoint_path'));

    if ($endpoint_path === '' || $endpoint_path[0] !== '/') {
      $form_state->setErrorByName('endpoint_path', $this->t('The endpoint path must start with /.'));
    }

    if (strpos($endpoint_path, ' ') !== FALSE) {
      $form_state->setErrorByName('endpoint_path', $this->t('The endpoint path cannot contain spaces.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $selected_node_types = $this->sanitizeCheckboxValues((array) $form_state->getValue('node_types'));

    $raw_fields = (array) $form_state->getValue('fields_wrapper');
    $clean_fields = [];
    $clean_entity_reference_modes = [];

    foreach ($selected_node_types as $bundle) {
      $clean_fields[$bundle] = $this->sanitizeCheckboxValues($raw_fields[$bundle] ?? []);

      $bundle_modes = [];
      $raw_modes = $raw_fields[$bundle . '_entity_ref_modes']['entity_reference_modes'][$bundle] ?? [];
      foreach ((array) $raw_modes as $field_name => $mode) {
        $field_name = (string) $field_name;
        if (!in_array($field_name, $clean_fields[$bundle], TRUE)) {
          continue;
        }

        $mode = (string) $mode;
        if (!in_array($mode, ['id', 'label', 'id_label'], TRUE)) {
          $mode = 'id';
        }
        $bundle_modes[$field_name] = $mode;
      }

      if ($bundle_modes !== []) {
        $clean_entity_reference_modes[$bundle] = $bundle_modes;
      }
    }

    $this->configFactory->getEditable('site_json_export.settings')
      ->set('endpoint_path', trim((string) $form_state->getValue('endpoint_path')))
      ->set('language', (string) $form_state->getValue('language'))
      ->set('node_types', $selected_node_types)
      ->set('fields', $clean_fields)
      ->set('entity_reference_modes', $clean_entity_reference_modes)
      ->save();

    $this->routeBuilder->rebuild();
  }

  /**
   * Returns selected values from a checkboxes element.
   */
  protected function sanitizeCheckboxValues(array $values) {
    $selected = [];

    foreach ($values as $key => $value) {
      if ($value !== 0 && $value !== '' && $value !== NULL) {
        $selected[] = (string) $key;
      }
    }

    return array_values(array_unique($selected));
  }

  /**
   * Determines if a node field should be offered for export.
   */
  protected function isExportableField($field_name, FieldDefinitionInterface $field_definition) {
    if ($field_definition->isComputed()) {
      return FALSE;
    }

    $excluded_fields = [
      'vid',
      'revision_timestamp',
      'revision_uid',
      'revision_log',
      'revision_default',
      'revision_translation_affected',
      'content_translation_source',
      'content_translation_outdated',
      'content_translation_status',
      'default_langcode',
    ];

    return !in_array($field_name, $excluded_fields, TRUE);
  }

}
