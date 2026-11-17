<?php

namespace Drupal\site_json_export\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for JSON content export.
 */
class SiteJsonExportController extends ControllerBase {

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Creates a new controller.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Returns a JSON export for configured content types and fields.
   */
  public function export(Request $request) {
    $config = $this->configFactory->get('site_json_export.settings');

    $node_types = (array) ($config->get('node_types') ?: []);
    $language = (string) ($config->get('language') ?: 'all');

    // Optional override from the URL: ?lang=it, ?lang=it,en,de or ?lang=all.
    $requested_language = trim((string) $request->query->get('lang', ''));
    $strict = FALSE;
    if ($requested_language !== '') {
      $language = $requested_language;
      $strict = TRUE;
    }
    $requested_langcodes = $this->resolveLangcodes($language, $strict);
    if ($requested_langcodes === NULL) {
      return new JsonResponse([
        'meta' => [
          'count' => 0,
          'path' => $request->getPathInfo(),
          'language' => $language,
          'message' => 'Unknown language code. Available: all, ' . implode(', ', array_keys($this->languageManager()->getLanguages())),
        ],
        'data' => [],
      ], 400);
    }

    $fields_by_bundle = (array) ($config->get('fields') ?: []);
    $entity_reference_modes = (array) ($config->get('entity_reference_modes') ?: []);

    if ($node_types === []) {
      return new JsonResponse([
        'meta' => [
          'count' => 0,
          'path' => $request->getPathInfo(),
          'language' => $language,
          'content_types' => [],
          'message' => 'No content type selected in module settings.',
        ],
        'data' => [],
      ]);
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('type', $node_types, 'IN')
      ->sort('changed', 'DESC');

    $node_ids = $query->execute();
    $nodes = $storage->loadMultiple($node_ids);

    $data = [];
    foreach ($nodes as $source_node) {
      foreach ($this->getExportTranslations($source_node, $requested_langcodes) as $node) {
        $data[] = $this->buildRow($node, $fields_by_bundle, $entity_reference_modes);
      }
    }

    return new JsonResponse([
      'meta' => [
        'count' => count($data),
        'path' => $request->getPathInfo(),
        'language' => $language,
        'languages' => $requested_langcodes === [] ? 'all' : $requested_langcodes,
        'content_types' => $node_types,
      ],
      'data' => $data,
    ]);
  }

  /**
   * Converts the language option into a list of langcodes.
   *
   * @param string $language
   *   "all", one langcode or a comma separated list.
   * @param bool $strict
   *   When TRUE an unknown langcode makes the whole value invalid.
   *
   * @return string[]|null
   *   Empty array for "all", list of langcodes, or NULL if a code is unknown.
   */
  protected function resolveLangcodes($language, $strict = TRUE) {
    $language = strtolower(trim((string) $language));
    if ($language === '' || $language === 'all') {
      return [];
    }

    $available = array_keys($this->languageManager()->getLanguages());
    $langcodes = [];
    foreach (explode(',', $language) as $langcode) {
      $langcode = trim($langcode);
      if ($langcode === '') {
        continue;
      }
      if (!in_array($langcode, $available, TRUE)) {
        if ($strict) {
          return NULL;
        }
        // Stale config value (language removed): ignore it.
        continue;
      }
      $langcodes[] = $langcode;
    }

    return array_values(array_unique($langcodes));
  }

  /**
   * Returns the published translations of a node to export.
   *
   * The original (default) translation always comes first, so the importer
   * creates the source post before its translations.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The loaded node.
   * @param string[] $langcodes
   *   Requested langcodes; empty array means all translations.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Translations to export.
   */
  protected function getExportTranslations($node, array $langcodes) {
    $original = $node->getUntranslated();
    $original_langcode = $original->language()->getId();

    $candidates = array_keys($node->getTranslationLanguages());
    usort($candidates, function ($a, $b) use ($original_langcode) {
      if ($a === $original_langcode) {
        return -1;
      }
      if ($b === $original_langcode) {
        return 1;
      }
      return strcmp($a, $b);
    });

    $translations = [];
    foreach ($candidates as $langcode) {
      if ($langcodes !== [] && !in_array($langcode, $langcodes, TRUE)) {
        continue;
      }
      $translation = $node->getTranslation($langcode);
      if (method_exists($translation, 'isPublished') && !$translation->isPublished()) {
        continue;
      }
      $translations[] = $translation;
    }

    return $translations;
  }

  /**
   * Builds one export row for a node translation.
   */
  protected function buildRow($node, array $fields_by_bundle, array $entity_reference_modes) {
    $bundle = $node->bundle();
    $fields = array_values(array_unique(array_filter((array) ($fields_by_bundle[$bundle] ?? []))));

    if ($fields === []) {
      $fields = ['title'];
    }

    $row = [
      'nid' => (int) $node->id(),
      'uuid' => $node->uuid(),
      'type' => $bundle,
      'langcode' => $node->language()->getId(),
      'default_langcode' => $node->isDefaultTranslation(),
      'original_langcode' => $node->getUntranslated()->language()->getId(),
      'translation_langcodes' => array_keys($node->getTranslationLanguages()),
    ];

    foreach ($fields as $field_name) {
      if (!$node->hasField($field_name)) {
        continue;
      }

      $mode = 'id';
      if (isset($entity_reference_modes[$bundle]) && is_array($entity_reference_modes[$bundle]) && isset($entity_reference_modes[$bundle][$field_name])) {
        $candidate_mode = (string) $entity_reference_modes[$bundle][$field_name];
        if (in_array($candidate_mode, ['id', 'label', 'id_label'], TRUE)) {
          $mode = $candidate_mode;
        }
      }

      $row[$field_name] = $this->normalizeField($node->get($field_name), $mode);
    }

    return $row;
  }

  /**
   * Normalizes a Drupal field value for JSON output.
   */
  protected function normalizeField(FieldItemListInterface $items, $entity_reference_mode = 'id') {
    if ($items->isEmpty()) {
      return NULL;
    }

    $field_type = $items->getFieldDefinition()->getType();

    if ($field_type === 'entity_reference') {
      $entity_reference_mode = in_array($entity_reference_mode, ['id', 'label', 'id_label'], TRUE) ? $entity_reference_mode : 'id';
      $values = [];

      foreach ($items as $item) {
        $target_id = !empty($item->target_id) ? (int) $item->target_id : NULL;
        if ($target_id === NULL) {
          continue;
        }

        if ($entity_reference_mode === 'id') {
          $values[] = $target_id;
          continue;
        }

        $label = NULL;
        if (isset($item->entity) && is_object($item->entity) && method_exists($item->entity, 'label')) {
          $label_value = $item->entity->label();
          $label = is_string($label_value) ? $label_value : (string) $label_value;
        }

        if ($entity_reference_mode === 'label') {
          $values[] = $label !== NULL && $label !== '' ? $label : (string) $target_id;
          continue;
        }

        $values[] = [
          'target_id' => $target_id,
          'label' => $label !== NULL ? $label : '',
        ];
      }

      return count($values) === 1 ? $values[0] : $values;
    }

    if ($field_type === 'image' || $field_type === 'file') {
      $values = [];

      foreach ($items as $item) {
        $entry = [
          'target_id' => $item->target_id ? (int) $item->target_id : NULL,
        ];

        if (!empty($item->target_id)) {
          $file = $this->entityTypeManager->getStorage('file')->load($item->target_id);
          if ($file instanceof FileInterface) {
            $entry['uri'] = $file->getFileUri();
            $entry['url'] = file_create_url($file->getFileUri());
            $entry['filename'] = $file->getFilename();
          }
        }

        if (isset($item->alt)) {
          $entry['alt'] = $item->alt;
        }

        if (isset($item->title)) {
          $entry['title'] = $item->title;
        }

        $values[] = $entry;
      }

      return count($values) === 1 ? $values[0] : $values;
    }

    $raw = $items->getValue();

    if (count($raw) === 1) {
      $first = $raw[0];

      if (array_key_exists('value', $first) && count($first) === 1) {
        return $first['value'];
      }

      return $first;
    }

    return $raw;
  }

}
