<?php

namespace Drupal\url_301_fixer\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search and replace form to fix old URLs that generate 301 redirects.
 */
class Url301FixerForm extends FormBase {

  /**
   * Node storage handler.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * Creates the form object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->nodeStorage = $entity_type_manager->getStorage('node');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'url_301_fixer_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $old_url = (string) ($form_state->getValue('old_url') ?? $form_state->get('old_url') ?? '');
    $new_url = (string) ($form_state->getValue('new_url') ?? $form_state->get('new_url') ?? '');
    $results = (array) ($form_state->get('search_results') ?? []);

    $form['description'] = [
      '#markup' => $this->t('Insert old URL and replacement URL. First run search, then replace only selected rows or all found matches.'),
    ];

    $form['old_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Old URL to find'),
      '#required' => TRUE,
      '#default_value' => $old_url,
    ];

    $form['new_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New URL to use'),
      '#required' => FALSE,
      '#default_value' => $new_url,
      '#description' => $this->t('Used only for replacement actions.'),
    ];

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['search'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search matches'),
      '#button_type' => 'primary',
      '#name' => 'search',
      '#submit' => ['::submitSearch'],
    ];

    if ($results !== []) {
      $header = [
        'title' => $this->t('Page title'),
        'langcode' => $this->t('Lang'),
        'path' => $this->t('Path'),
        'fields' => $this->t('Fields with matches'),
        'occurrences' => $this->t('Occurrences'),
      ];

      $options = [];
      foreach ($results as $key => $row) {
        $options[$key] = [
          'title' => $row['title'],
          'langcode' => $row['langcode'],
          'path' => $row['path'],
          'fields' => implode(', ', $row['fields']),
          'occurrences' => $row['occurrences'],
        ];
      }

      $form['matches'] = [
        '#type' => 'details',
        '#title' => $this->t('Matched pages'),
        '#open' => TRUE,
      ];

      $form['matches']['results'] = [
        '#type' => 'tableselect',
        '#header' => $header,
        '#options' => $options,
        '#empty' => $this->t('No matches found.'),
      ];

      $form['actions']['replace_selected'] = [
        '#type' => 'submit',
        '#value' => $this->t('Replace on selected pages'),
        '#name' => 'replace_selected',
        '#submit' => ['::submitReplaceSelected'],
      ];

      $form['actions']['replace_all'] = [
        '#type' => 'submit',
        '#value' => $this->t('Replace on all found pages'),
        '#name' => 'replace_all',
        '#submit' => ['::submitReplaceAll'],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = (string) ($form_state->getTriggeringElement()['#name'] ?? '');
    $old_url = trim((string) $form_state->getValue('old_url'));

    if ($old_url === '') {
      $form_state->setErrorByName('old_url', $this->t('Old URL is required.'));
    }

    if (in_array($trigger, ['replace_selected', 'replace_all'], TRUE)) {
      $new_url = trim((string) $form_state->getValue('new_url'));
      if ($new_url === '') {
        $form_state->setErrorByName('new_url', $this->t('New URL is required for replacement.'));
      }

      if ($new_url === $old_url && $new_url !== '') {
        $form_state->setErrorByName('new_url', $this->t('New URL must be different from old URL.'));
      }

      if ($trigger === 'replace_selected') {
        $selected = $this->getSelectedResultKeys((array) $form_state->getValue(['matches', 'results']));
        if ($selected === []) {
          $form_state->setErrorByName('matches][results', $this->t('Select at least one page.'));
        }
      }
    }
  }

  /**
   * Search button submit.
   */
  public function submitSearch(array &$form, FormStateInterface $form_state) {
    $old_url = trim((string) $form_state->getValue('old_url'));
    $new_url = trim((string) $form_state->getValue('new_url'));

    $results = $this->findMatches($old_url);

    $form_state->set('old_url', $old_url);
    $form_state->set('new_url', $new_url);
    $form_state->set('search_results', $results);
    $form_state->setRebuild(TRUE);

    $this->messenger()->addStatus($this->t('Found @pages pages with @occ matches.', [
      '@pages' => count($results),
      '@occ' => $this->countOccurrences($results),
    ]));
  }

  /**
   * Replace only selected rows.
   */
  public function submitReplaceSelected(array &$form, FormStateInterface $form_state) {
    $old_url = trim((string) $form_state->getValue('old_url'));
    $new_url = trim((string) $form_state->getValue('new_url'));
    $selected = $this->getSelectedResultKeys((array) $form_state->getValue(['matches', 'results']));

    $replace_stats = $this->replaceMatches($old_url, $new_url, $selected);

    $results = $this->findMatches($old_url);

    $form_state->set('old_url', $old_url);
    $form_state->set('new_url', $new_url);
    $form_state->set('search_results', $results);
    $form_state->setRebuild(TRUE);

    $this->messenger()->addStatus($this->t('Updated @pages pages and replaced @occ occurrences.', [
      '@pages' => $replace_stats['updated_pages'],
      '@occ' => $replace_stats['replaced_occurrences'],
    ]));

    if ($replace_stats['failed_pages'] > 0) {
      $this->messenger()->addError($this->t('Could not save @count pages.', [
        '@count' => $replace_stats['failed_pages'],
      ]));
    }
  }

  /**
   * Replace on every found row.
   */
  public function submitReplaceAll(array &$form, FormStateInterface $form_state) {
    $old_url = trim((string) $form_state->getValue('old_url'));
    $new_url = trim((string) $form_state->getValue('new_url'));

    $results = (array) ($form_state->get('search_results') ?? []);
    $all_keys = array_keys($results);

    $replace_stats = $this->replaceMatches($old_url, $new_url, $all_keys);

    $fresh_results = $this->findMatches($old_url);

    $form_state->set('old_url', $old_url);
    $form_state->set('new_url', $new_url);
    $form_state->set('search_results', $fresh_results);
    $form_state->setRebuild(TRUE);

    $this->messenger()->addStatus($this->t('Updated @pages pages and replaced @occ occurrences.', [
      '@pages' => $replace_stats['updated_pages'],
      '@occ' => $replace_stats['replaced_occurrences'],
    ]));

    if ($replace_stats['failed_pages'] > 0) {
      $this->messenger()->addError($this->t('Could not save @count pages.', [
        '@count' => $replace_stats['failed_pages'],
      ]));
    }
  }

  /**
   * Finds matches grouped by node translation.
   */
  protected function findMatches($old_url) {
    $old_url = (string) $old_url;
    if ($old_url === '') {
      return [];
    }

    $nids = $this->nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    if ($nids === []) {
      return [];
    }

    $nodes = $this->nodeStorage->loadMultiple($nids);
    $results = [];

    foreach ($nodes as $node) {
      foreach ($node->getTranslationLanguages() as $langcode => $language) {
        $translation = $node->getTranslation($langcode);
        $occurrences = 0;
        $fields_with_matches = [];

        foreach ($translation->getFields() as $field_name => $field) {
          if ($field->isEmpty()) {
            continue;
          }

          $field_type = $field->getFieldDefinition()->getType();
          if (!$this->isSearchableFieldType($field_type)) {
            continue;
          }

          $field_occurrences = $this->countFieldOccurrences($field, $old_url);
          if ($field_occurrences > 0) {
            $occurrences += $field_occurrences;
            $fields_with_matches[] = $field_name . ' (' . $field_occurrences . ')';
          }
        }

        if ($occurrences > 0) {
          $key = $node->id() . ':' . $langcode;
          $results[$key] = [
            'nid' => (int) $node->id(),
            'langcode' => $langcode,
            'title' => (string) $translation->label(),
            'path' => '/node/' . $node->id(),
            'fields' => $fields_with_matches,
            'occurrences' => $occurrences,
          ];
        }
      }
    }

    ksort($results);
    return $results;
  }

  /**
   * Replaces URL occurrences on selected node translations.
   */
  protected function replaceMatches($old_url, $new_url, array $target_keys) {
    $old_url = (string) $old_url;
    $new_url = (string) $new_url;

    $stats = [
      'updated_pages' => 0,
      'replaced_occurrences' => 0,
      'failed_pages' => 0,
    ];

    if ($old_url === '' || $new_url === '' || $target_keys === []) {
      return $stats;
    }

    $targets = [];
    foreach ($target_keys as $key) {
      $parts = explode(':', (string) $key, 2);
      if (count($parts) !== 2 || !is_numeric($parts[0])) {
        continue;
      }

      $nid = (int) $parts[0];
      $langcode = (string) $parts[1];
      $targets[$nid][] = $langcode;
    }

    if ($targets === []) {
      return $stats;
    }

    $nodes = $this->nodeStorage->loadMultiple(array_keys($targets));

    foreach ($targets as $nid => $langcodes) {
      if (!isset($nodes[$nid])) {
        continue;
      }

      $node = $nodes[$nid];
      $unique_langcodes = array_values(array_unique($langcodes));

      foreach ($unique_langcodes as $langcode) {
        if (!$node->hasTranslation($langcode)) {
          continue;
        }

        $translation = $node->getTranslation($langcode);
        $node_changed = FALSE;
        $node_occurrences = 0;

        foreach ($translation->getFields() as $field) {
          if ($field->isEmpty()) {
            continue;
          }

          $field_type = $field->getFieldDefinition()->getType();
          if (!$this->isSearchableFieldType($field_type)) {
            continue;
          }

          $storage_definition = $field->getFieldDefinition()->getFieldStorageDefinition();
          $main_property = $storage_definition->getMainPropertyName();
          if ($main_property === NULL || $main_property === '') {
            continue;
          }

          foreach ($field as $item) {
            if (!$item->hasField($main_property)) {
              continue;
            }

            $value = (string) $item->get($main_property)->getValue();
            if ($value === '' || strpos($value, $old_url) === FALSE) {
              continue;
            }

            $new_value = str_replace($old_url, $new_url, $value, $replaced_here);
            if ($replaced_here > 0) {
              $item->set($main_property, $new_value);
              $node_occurrences += $replaced_here;
              $node_changed = TRUE;
            }
          }
        }

        if ($node_changed) {
          try {
            $translation->save();
            $stats['updated_pages']++;
            $stats['replaced_occurrences'] += $node_occurrences;
          }
          catch (\Exception $e) {
            $stats['failed_pages']++;
            $this->messenger()->addError($this->t('Save failed for node @nid (@lang).', [
              '@nid' => $nid,
              '@lang' => $langcode,
            ]));
          }
        }
      }
    }

    return $stats;
  }

  /**
   * Returns selected keys from a tableselect value.
   */
  protected function getSelectedResultKeys(array $values) {
    $selected = [];

    foreach ($values as $key => $value) {
      if ($value !== 0 && $value !== '0' && $value !== NULL && $value !== '') {
        $selected[] = (string) $key;
      }
    }

    return $selected;
  }

  /**
   * Counts occurrences found in one field.
   */
  protected function countFieldOccurrences($field, $needle) {
    $storage_definition = $field->getFieldDefinition()->getFieldStorageDefinition();
    $main_property = $storage_definition->getMainPropertyName();

    if ($main_property === NULL || $main_property === '') {
      return 0;
    }

    $count = 0;
    foreach ($field as $item) {
      if (!$item->hasField($main_property)) {
        continue;
      }

      $value = (string) $item->get($main_property)->getValue();
      if ($value === '') {
        continue;
      }

      $count += substr_count($value, $needle);
    }

    return $count;
  }

  /**
   * Counts total occurrences in a search result set.
   */
  protected function countOccurrences(array $results) {
    $total = 0;
    foreach ($results as $row) {
      $total += (int) ($row['occurrences'] ?? 0);
    }
    return $total;
  }

  /**
   * Returns TRUE for field types that can contain textual URLs.
   */
  protected function isSearchableFieldType($field_type) {
    return in_array((string) $field_type, [
      'string',
      'string_long',
      'text',
      'text_long',
      'text_with_summary',
      'link',
      'uri',
      'email',
    ], TRUE);
  }

}
