<?php
namespace Drupal\content_hierarchy_path;

use Drupal\content_hierarchy\ContentHierarchyStorage;
use Drupal\content_hierarchy\ContentHierarchyWidgets;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\pathauto\PathautoGeneratorInterface;

class ContentHierarchyPathUpdater {

  use StringTranslationTrait;

  /**
   * @var \Drupal\pathauto\PathautoGeneratorInterface
   */
  protected $pathautoGenerator;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\content_hierarchy\ContentHierarchyStorage
   */
  protected $contentHierarchyStorage;

  /**
   * @var \Drupal\content_hierarchy\ContentHierarchyWidgets
   */
  protected $contentHierarchyWidgets;

  /**
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  function __construct(ContentHierarchyStorage $contentHierarchyStorage, ContentHierarchyWidgets $contentHierarchyWidgets, PathautoGeneratorInterface $pathautoGenerator, ConfigFactoryInterface $configFactory, QueueFactory $queueFactory) {
    $this->contentHierarchyStorage = $contentHierarchyStorage;
    $this->contentHierarchyWidgets = $contentHierarchyWidgets;
    $this->pathautoGenerator = $pathautoGenerator;
    $this->configFactory = $configFactory;
    $this->queue = $queueFactory->get('content_hierarchy_path_update');
  }

  public function updateConfigs() {
    $active_types = $this->getActiveSupportedEntityTypes();

    $settings = $this->configFactory->get('pathauto.settings')->get('punctuation');
    if ($settings['slash'] != 2) {
      $settings['slash'] = 2;
      $this->configFactory->getEditable('pathauto.settings')
        ->set('punctuation', $settings)
        ->save();
    }

    // There are no entity types or bundles selected so delete pattern if it exists
    if (empty($active_types)) {
      // TODO: find a way to delete all patterns from content hierarchy
      $pattern = PathautoPattern::load('content_hierarchy_node');
      if (!is_null($pattern)) {
        $pattern->delete();
      }
    } else {
      foreach ($this->contentHierarchyWidgets->getSupportedEntityTypes() as $entity_type_id => $type_info) {
        if (empty($active_types[$entity_type_id])) {
          $pattern = PathautoPattern::load('content_hierarchy_' . $entity_type_id);
          if (!is_null($pattern)) {
            $pattern->delete();
          }
        }
        else {
          $update = FALSE;
          $pattern = PathautoPattern::load('content_hierarchy_' . $entity_type_id);
          // If pattern doesn't already exist, create one
          if (is_null($pattern)) {
            $pattern = PathautoPattern::create([
              'id' => 'content_hierarchy_' . $entity_type_id,
              'label' => $this->t('Content Hierarchy pattern'),
              'type' => 'canonical_entities:' . $entity_type_id,
              'pattern' => '[' . $type_info['token_type'] . ':ancestors-joined-path]/[' . $type_info['token_type'] . ':' . $type_info['token_label_field'] . ']',
              'weight' => 0,
              'status' => TRUE
            ]);
            $update = TRUE;
          }
          $instance_id = NULL;
          foreach ($pattern->getSelectionConditions()
                     ->getInstanceIds() as $id) {
            $condition = $pattern->getSelectionCondition($id);
            if (is_null($instance_id) && $condition->getPluginId() == 'node_type') {
              $instance_id = $id;
            }
            elseif (is_null($instance_id) && substr($condition->getPluginId(), 0, 14) == 'entity_bundle:') {
              $instance_id = $id;
            }
            else {
              $pattern->removeSelectionCondition($id);
              $update = TRUE;
            }
          }

          $bundle_ids = $active_types[$entity_type_id]['bundle_ids'];
          $bundles = [];
          foreach ($bundle_ids as $id) {
            $bundles[$id] = $id;
          }
          $id = 'entity_bundle:' . $entity_type_id;

          if (!is_null($instance_id)) {
            $condition = $pattern->getSelectionCondition($instance_id);
            if (array_keys($condition->getConfiguration()['bundles']) != $bundle_ids) {
              $configuration = $condition->getConfiguration();
              $configuration['bundles'] = $bundles;
              $condition->setConfiguration($configuration);
              $update = TRUE;
            }
          }
          else {
            $pattern->addSelectionCondition([
              'id' => $id,
              'bundles' => $bundles,
              'negate' => FALSE,
              'context_mapping' => [$entity_type_id => $entity_type_id],
            ]);
            $update = TRUE;
          }

          if ($update) {
            $pattern->save();
          }
        }
      }
    }
  }

  /**
   * @param int $content_id
   * @param string $langcode
   */
  public function updatePath($content_id, $langcode = NULL) {
    $content = $this->contentHierarchyStorage->load($content_id, $langcode);
    if (empty($content)) {
      return;
    }
    $entity = $content->getEntity();
    if (empty($entity)) {
      return;
    }
    $this->pathautoGenerator->updateEntityAlias($entity, 'update', ['language' => $langcode]);

    foreach ($content->getChildren() as $child) {
      $this->queue->createItem(['content_id' => $child->id(), 'langcode' => $child->getLangcode()]);
    }
  }

  /**
   * @return array
   */
  public function getActiveSupportedEntityTypes() {
    $types = [];
    $supported = $this->contentHierarchyWidgets->getSupportedEntityTypes();
    $config = $this->configFactory->get('content_hierarchy.hierarchy_settings');
    $entity_bundles = $config->get('entity_bundles') ?? [];
    foreach ($entity_bundles as $entity_type_id => $bundle_ids) {
      if (isset($supported[$entity_type_id]) && !empty($bundle_ids) && isset($supported[$entity_type_id]['token_type'])) {
        $types[$entity_type_id] = $supported[$entity_type_id];
        $types[$entity_type_id]['bundle_ids'] = $bundle_ids;
      }
    }
    return $types;
  }
}
