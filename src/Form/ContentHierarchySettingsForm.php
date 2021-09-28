<?php

namespace Drupal\content_hierarchy\Form;

use Drupal\content_hierarchy\ContentHierarchyData;
use Drupal\content_hierarchy\ContentHierarchyWidgets;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ContentHierarchySettingsForm extends ConfigFormBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity type type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * @var \Drupal\content_hierarchy\ContentHierarchyData
   */
  protected $contentHierarchyData;

  /**
   * @var \Drupal\content_hierarchy\ContentHierarchyData
   */
  protected $contentHierarchyWidgets;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('content_hierarchy.data'),
      $container->get('content_hierarchy.widgets'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, ContentHierarchyData $contentHierarchyData, ContentHierarchyWidgets $contentHierarchyWidgets, EntityTypeManagerInterface $entityTypeManager, EntityTypeBundleInfoInterface $entityTypeBundleInfo, MessengerInterface $messenger) {
    parent::__construct($config_factory);
    $this->contentHierarchyWidgets = $contentHierarchyWidgets;
    $this->contentHierarchyData = $contentHierarchyData;
    $this->messenger = $messenger;
    $this->entityTypeManager = $entityTypeManager;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
  }

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
        'Configure how the content hierarchy works and what entity bundles are included in the hierarchy lists.'
      ),
    ];

    $options = [
      10 => 10,
      25 => 25,
      50 => 50,
      100 => 100,
      200 => 200
    ];
    $form['contents_per_page_admin'] = [
      '#type' => 'select',
      '#title' => $this->t('Content per page'),
      '#description' => $this->t('How many content items to display per page in sortable overview.'),
      '#options' => $options,
      '#default_value' => $config->get('contents_per_page_admin') ?? 50
    ];

    $form['multilingual'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Translatable'),
      '#description' => $this->t('Have different content trees for each language.'),
      '#default_value' => $config->get('multilingual') ?? TRUE
    ];

    $form['entity_bundles'] = array(
      '#type' => 'details',
      '#title' => $this->t('Entity types'),
      '#open' => TRUE
    );

    $bundles = $config->get('entity_bundles') ?? [];
    $entity_types = $this->contentHierarchyWidgets->getSupportedEntityTypes();
    foreach ($entity_types as $entity_type) {
      $options = [];
      foreach ($entity_type['bundles'] as $bundle_id => $bundle) {
        $options[$bundle_id] = $bundle['label'];
      }

      $form['entity_bundles'][$entity_type['id']] = array(
        '#type' => 'checkboxes',
        '#title' => $entity_type['label'],
        '#description' => $this->t(''),
        '#options' => $options,
        '#default_value' => $bundles[$entity_type['id']] ?? []
      );
    }

    return parent::buildform($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('content_hierarchy.hierarchy_settings');

    $config->set('contents_per_page_admin', $form_state->getValue('contents_per_page_admin'));

    $multilingual = $form_state->getValue('multilingual') == 1;
    if ($config->get('multilingual') != $multilingual) {
      if (!$multilingual) {
        $this->contentHierarchyData()->deleteAllButOneLanguage(\Drupal::languageManager()->getDefaultLanguage()->getId());
      }
      $config->set('multilingual', $multilingual);
    }

    $bundles = [];
    $entity_types = $this->contentHierarchyWidgets->getSupportedEntityTypes();
    foreach ($entity_types as $entity_type) {
      $bundles[$entity_type['id']] = [];
      foreach ($form_state->getValue(['entity_bundles', $entity_type['id']], []) as $bundle) {
        if (!empty($bundle)) {
          $bundles[$entity_type['id']][] = $bundle;
        }
      }
    }
    $config->set('entity_bundles', $bundles);

    $config->save();

    foreach ($bundles as $entity_type_id => $entity_bundles) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

      $existing_bundles = [];
      foreach ($this->entityTypeBundleInfo->getBundleInfo($entity_type_id) as $bundle_id => $bundle) {
        $existing_bundles[] = $bundle_id;
      }

      if (empty($entity_bundles)) {
        foreach ($existing_bundles as $bundle_id) {
          $this->removeEntityBundle($entity_type, $bundle_id);
        }

        $fieldStorage = FieldStorageConfig::loadByName($entity_type_id, 'content_hierarchy');
        if (!empty($fieldStorage)) {
          $fieldStorage->delete();
        }
      } else {
        $this->addFieldStorage($entity_type_id);

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
   * @param EntityTypeInterface $entity_type
   * @param string $bundle
   */
  function addEntityBundle(EntityTypeInterface $entity_type, $bundle) {
    $field = FieldConfig::loadByName($entity_type->id(), $bundle, 'content_hierarchy');
    if (empty($field)) {
      $this->addPlacementField($entity_type->id(), $bundle);

      $langcodes = [];
      $bundle_field = $entity_type->getKey('bundle');
      $entity_ids = \Drupal::entityQuery($entity_type->id())
        ->condition($bundle_field, $bundle)
        ->execute();
      $entities = \Drupal::entityTypeManager()->getStorage($entity_type->id())->loadMultiple($entity_ids);
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
   * @param EntityTypeInterface $entity_type
   * @param string $bundle
   */
  function removeEntityBundle(EntityTypeInterface $entity_type, $bundle) {
    $field = FieldConfig::loadByName($entity_type->id(), $bundle, 'content_hierarchy');
    if (!empty($field)) {
      $field->delete();

      $bundle_field = $entity_type->getKey('bundle');
      $entity_ids = \Drupal::entityQuery($entity_type->id())
        ->condition($bundle_field, $bundle)
        ->execute();
      $content_ids = $this->contentHierarchyData()->findEntityIds($entity_type->id(), $entity_ids);
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

  /**
   * @param string $entity_type_id
   * @param string $bundle
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  function addPlacementField($entity_type_id, $bundle) {
    $entityTypemanager = \Drupal::entityTypeManager();

    // Add or remove the body field, as needed.
    $field_storage = $this->addFieldStorage($entity_type_id);

    $field = FieldConfig::loadByName($entity_type_id, $bundle, 'content_hierarchy');
    if (empty($field)) {
      $field = FieldConfig::create([
        'field_storage' => $field_storage,
        'field_name' => 'content_hierarchy',
        'langcode' => \Drupal::languageManager()->getDefaultLanguage()->getId(),
        'entity_type' => $entity_type_id,
        'bundle' => $bundle,
        'translatable' => TRUE,
        'label' => $this->t('Content Hierarchy', [], ['langcode' => \Drupal::languageManager()->getDefaultLanguage()->getId()])
      ]);
      $field->save();

      // Assign widget settings for the 'default' form mode.
      $displayForm = $entityTypemanager
        ->getStorage('entity_form_display')
        ->load($entity_type_id . '.' . $bundle . '.default');
      if ($displayForm) {
        $displayForm->setComponent('content_hierarchy', [
          'type' => 'content_hierarchy_modal'
        ]);
        $displayForm->save();
      }
      unset($displayForm);

      // Assign display settings for the 'default' and 'teaser' view modes.
      $displayDefault = $entityTypemanager
        ->getStorage('entity_view_display')
        ->load($entity_type_id . '.' . $bundle . '.default');
      if ($displayDefault) {
        $displayDefault->removeComponent('content_hierarchy');
        $displayDefault->save();
      }
      unset($displayDefault);

      // The teaser view mode is created by the Standard profile and therefore
      // might not exist.
      $viewModes = \Drupal::service('entity_display.repository')
        ->getViewModes($entity_type_id);
      if (isset($viewModes['teaser'])) {
        $displayTeaser = $entityTypemanager
          ->getStorage('entity_view_display')
          ->load($entity_type_id . '.' . $bundle . '.teaser');
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
