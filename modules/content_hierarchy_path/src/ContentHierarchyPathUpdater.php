<?php
namespace Drupal\content_hierarchy_path;

use Drupal\content_hierarchy\ContentHierarchyStorage;
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
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  function __construct(ContentHierarchyStorage $contentHierarchyStorage, PathautoGeneratorInterface $pathautoGenerator, ConfigFactoryInterface $configFactory, QueueFactory $queueFactory) {
    $this->contentHierarchyStorage = $contentHierarchyStorage;
    $this->pathautoGenerator = $pathautoGenerator;
    $this->configFactory = $configFactory;
    $this->queue = $queueFactory->get('content_hierarchy_path_update');
  }

  public function updateConfigs() {
    $config = $this->configFactory->get('content_hierarchy.hierarchy_settings');
    $entity_bundles = $config->get('entity_bundles') ?? [];

    $settings = $this->configFactory->get('pathauto.settings')->get('punctuation');
    if ($settings['slash'] != 2) {
      $settings['slash'] = 2;
      $this->configFactory->getEditable('pathauto.settings')
        ->set('punctuation', $settings)
        ->save();
    }

    // There are no entity types or bundles selected so delete pattern if it exists
    if (empty($entity_bundles)) {
      // TODO: find a way to delete all patterns from content hierarchy
      $pattern = PathautoPattern::load('content_hierarchy_node');
      if (!is_null($pattern)) {
        $pattern->delete();
      }
    } else {
      foreach ($entity_bundles as $entity_type => $bundle_ids) {
        if (empty($bundle_ids)) {
          $pattern = PathautoPattern::load('content_hierarchy_' . $entity_type);
          if (!is_null($pattern)) {
            $pattern->delete();
          }
        }
        else {
          $update = FALSE;
          $pattern = PathautoPattern::load('content_hierarchy_' . $entity_type);
          // If pattern doesn't already exist, create one
          if (is_null($pattern)) {
            $pattern = PathautoPattern::create([
              'id' => 'content_hierarchy_' . $entity_type,
              'label' => $this->t('Content Hierarchy pattern'),
              'type' => 'canonical_entities:' . $entity_type,
              'pattern' => '[' . $entity_type . ':ancestors-joined-path]/[' . $entity_type . ':title]',
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

          $bundles = [];
          foreach ($bundle_ids as $id) {
            $bundles[$id] = $id;
          }
          $id = 'entity_bundle:' . $entity_type;
          if ($entity_type == 'node') {
            $id = 'node_type';
          }

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
              'context_mapping' => [$entity_type => $entity_type],
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
}
