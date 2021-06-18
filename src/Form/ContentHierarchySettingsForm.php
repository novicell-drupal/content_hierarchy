<?php

namespace Drupal\content_hierarchy\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

class ContentHierarchySettingsForm extends ConfigFormBase {

  /**
   * @var \Drupal\content_hierarchy\ContentHierarchyData
   */
  protected $contentHierarchyData;

  /**
   * Gets the configuration names that will be editable.
   *
   * @return array
   *   An array of configuration object names that are editable if called in
   *   conjunction with the trait's config() method.
   */
  protected function getEditableConfigNames() {
    return [
      'content_hierarchy.hierarchy_settings'
    ];
  }

  /**
   * Returns a unique string identifying the form.
   *
   * The returned ID should be a unique string that can be a valid PHP function
   * name, since it's used in hook implementation names such as
   * hook_form_FORM_ID_alter().
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'content_hierarchy_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('content_hierarchy.hierarchy_settings');

    $form['#tree'] = TRUE;
    $form['settings'] = [
      '#type' => 'item',
      '#open' => TRUE,
      '#title' => $this->t('Content Hierarchy settings'),
      '#description' => $this->t(
        'Configure how the content hierarchy works and what nodes are included in the hierarchy lists.'
      ),
      '#prefix' => '<div id="table-wrapper">',
      '#suffix' => '</div>',
    ];

    $options = [
      'draggable' => $this->t('Draggable'),
      'foldable' => $this->t('Foldable')
    ];
    $form['overview_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Overview type'),
      '#description' => $this->t('What type of overview should be used for content hierarchy?'),
      '#options' => $options,
      '#default_value' => $config->get('overview_type') ?? 'draggable'
    ];

    $form['multilingual'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Translatable'),
      '#description' => $this->t('Have different content trees for each language.'),
      '#default_value' => $config->get('multilingual') ?? TRUE
    ];

    $bundles = $config->get('entity_bundles') ?? [];
    $options = [];
    foreach(NodeType::loadMultiple() as $id => $node_type) {
      $options[$id] = $node_type->label();
    }

    $form['entity_bundles']['node'] = array(
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types'),
      '#description' => $this->t(''),
      '#options' => $options,
      '#default_value' => $bundles['node'] ?? []
    );

    return parent::buildform($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('content_hierarchy.hierarchy_settings');

    $config->set('overview_type', $form_state->getValue('overview_type'));

    $multilingual = $form_state->getValue('multilingual') == 1;
    if ($config->get('multilingual') != $multilingual) {
      if (!$multilingual) {
        $this->contentHierarchyData()->deleteAllButOneLanguage(\Drupal::languageManager()->getDefaultLanguage()->getId());
      }
      $config->set('multilingual', $multilingual);
    }

    $bundles = ['node' => []];
    foreach ($form_state->getValue(['entity_bundles', 'node'], []) as $bundle) {
      if (!empty($bundle)) {
        $bundles['node'][] = $bundle;
      }
    }
    $config->set('entity_bundles', $bundles);

    $config->save();

    foreach ($bundles as $entity_type => $entity_bundles) {
      $existing_bundles = [];
      foreach(NodeType::loadMultiple() as $bundle => $node_type) {
        $existing_bundles[] = $bundle;
      }

      if (empty($entity_bundles)) {
        foreach ($existing_bundles as $bundle) {
          $this->removeEntityBundle($entity_type, $bundle);
        }

        $fieldStorage = FieldStorageConfig::loadByName($entity_type, 'content_hierarchy');
        if (!empty($fieldStorage)) {
          $fieldStorage->delete();
        }
      } else {
        $this->addFieldStorage($entity_type);

        foreach ($existing_bundles as $bundle) {
          if (in_array($bundle, $entity_bundles)) {
            $this->addEntityBundle($entity_type, $bundle);
          } else {
            $this->removeEntityBundle($entity_type, $bundle);
          }
        }

      }
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Remove instances of an entity type or bundle from the content tree.
   *
   * @param string $entity_type
   * @param string $bundle
   */
  function addEntityBundle($entity_type, $bundle) {
    $field = FieldConfig::loadByName($entity_type, $bundle, 'content_hierarchy');
    if (empty($field)) {
      $this->addPlacementField($entity_type, $bundle);

      $langcodes = [];
      $entity_ids = \Drupal::entityQuery($entity_type)
        ->condition('type', $bundle)
        ->execute();
      $entities = \Drupal::entityTypeManager()->getStorage($entity_type)->loadMultiple($entity_ids);
      $config = $this->config('content_hierarchy.hierarchy_settings');
      foreach ($entities as $entity) {
        $this->contentHierarchyData()->setEntityPlacement($entity, 0);
        $langcodes[$entity->language()->getId()] = $entity->language()->getId();
        if ($config->get('multilingual') ?? $entity instanceof ContentEntityInterface) {
          /** @var ContentEntityInterface $translatable */
          $translatable = $entity;
          $languages = $translatable->getTranslationLanguages();
          foreach ($languages as $language) {
            $langcodes[$language->getId()] = $language->getId();
            $content_id = $this->contentHierarchyData()->findEntity($entity);
            $this->contentHierarchyData()->setContentPlacement($content_id, $language->getId(), 0, $content_id);
          }
        }
      }
      $tags = [];
      foreach ($langcodes as $langcode) {
        $tags[] = 'content_hierarchy_list:' . $langcode;
      }
      Cache::invalidateTags($tags);
    }
  }

  /**
   * Remove instances of an entity type or bundle from the content tree.
   *
   * @param string $entity_type
   * @param string $bundle
   */
  function removeEntityBundle($entity_type, $bundle) {
    $field = FieldConfig::loadByName($entity_type, $bundle, 'content_hierarchy');
    if (!empty($field)) {
      $field->delete();

      $entity_ids = \Drupal::entityQuery($entity_type)
        ->condition('type', $bundle)
        ->execute();
      $content_ids = $this->contentHierarchyData()->findEntityIds($entity_type, $entity_ids);
      $this->contentHierarchyData()->deleteMultiple($content_ids);
      $tags = [];
      foreach (\Drupal::languageManager()->getLanguages() as $language) {
        $tags[] = 'content_hierarchy_list:' . $language->getId();
      }
      Cache::invalidateTags($tags);
    }
  }

  function addFieldStorage($entity_type) {
    $fieldStorage = FieldStorageConfig::loadByName($entity_type, 'content_hierarchy');
    if (empty($fieldStorage)) {
      $fieldStorage = FieldStorageConfig::create([
        'field_name' => 'content_hierarchy',
        'langcode' => \Drupal::languageManager()->getDefaultLanguage()->getId(),
        'entity_type' => $entity_type,
        'type' => 'content_hierarchy',
        'settings' => [],
        'module' => 'content_hierarchy',
        'locked' => TRUE,
        'cardinality' => 1,
        'translatable' => TRUE,
        'persist_with_no_fields' => TRUE,
        'custom_storage' => FALSE,
      ]);
      $fieldStorage->save();
    }
    return $fieldStorage;
  }

  function addPlacementField($entity_type, $bundle) {
    $entityTypemanager = \Drupal::entityTypeManager();

    // Add or remove the body field, as needed.
    $field_storage = $this->addFieldStorage($entity_type);

    $field = FieldConfig::loadByName($entity_type, $bundle, 'content_hierarchy');
    if (empty($field)) {
      $field = FieldConfig::create([
        'field_storage' => $field_storage,
        'field_name' => 'content_hierarchy',
        'langcode' => \Drupal::languageManager()->getDefaultLanguage()->getId(),
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'translatable' => TRUE,
        'label' => $this->t('Content Hierarchy', [], ['langcode' => \Drupal::languageManager()->getDefaultLanguage()->getId()])
      ]);
      $field->save();

      // Assign widget settings for the 'default' form mode.
      $displayForm = $entityTypemanager
        ->getStorage('entity_form_display')
        ->load($entity_type . '.' . $bundle . '.default')
        ->setComponent('content_hierarchy', [
          'type' => 'content_hierarchy_select'
        ]);
      $displayForm->save();
      unset($displayForm);

      // Assign display settings for the 'default' and 'teaser' view modes.
      $displayDefault = $entityTypemanager
        ->getStorage('entity_view_display')
        ->load($entity_type . '.' . $bundle . '.default')
        ->removeComponent('content_hierarchy');
      $displayDefault->save();
      unset($displayDefault);

      // The teaser view mode is created by the Standard profile and therefore
      // might not exist.
      $viewModes = \Drupal::service('entity_display.repository')
        ->getViewModes($entity_type);
      if (isset($viewModes['teaser'])) {
        $displayTeaser = $entityTypemanager
          ->getStorage('entity_view_display')
          ->load($entity_type . '.' . $bundle . '.teaser');
        if (!empty($displayTeaser)) {
          $displayTeaser->removeComponent('content_hierarchy');
          $displayTeaser->save();
        }
        unset($displayTeaser);
      }
    }
  }

  /**
   * Gets the content hierarchy data service.
   *
   * @return \Drupal\content_hierarchy\ContentHierarchyData
   */
  protected function contentHierarchyData() {
    if (!$this->contentHierarchyData) {
      $this->contentHierarchyData = \Drupal::service('content_hierarchy.data');
    }
    return $this->contentHierarchyData;
  }
}
