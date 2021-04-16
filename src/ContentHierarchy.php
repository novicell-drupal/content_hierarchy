<?php
namespace Drupal\content_hierarchy;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\entity_hierarchy\Storage\EntityTreeNodeMapperInterface;
use Drupal\entity_hierarchy\Storage\NestedSetNodeKeyFactory;
use Drupal\entity_hierarchy\Storage\NestedSetStorageFactory;
use Drupal\node\Entity\Node;

class ContentHierarchy {

  /**
   * The nested set storage factory service.
   *
   * @var \Drupal\entity_hierarchy\Storage\NestedSetStorageFactory
   */
  protected $storageFactory;

  /**
   * The node key factory service.
   *
   * @var \Drupal\entity_hierarchy\Storage\NestedSetNodeKeyFactory
   */
  protected $nodeKeyFactory;

  /**
   * The entity tree node mapper service.
   *
   * @var \Drupal\entity_hierarchy\Storage\EntityTreeNodeMapperInterface
   */
  protected $mapper;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * HierarchyBasedBreadcrumbBuilder constructor.
   *
   * @param \Drupal\entity_hierarchy\Storage\NestedSetStorageFactory $storage_factory
   *   The nested set storage factory service.
   * @param \Drupal\entity_hierarchy\Storage\NestedSetNodeKeyFactory $node_key_factory
   *   The node key factory service.
   * @param \Drupal\entity_hierarchy\Storage\EntityTreeNodeMapperInterface $mapper
   *   The entity tree node mapper service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   */
  public function __construct(NestedSetStorageFactory $storage_factory, NestedSetNodeKeyFactory $node_key_factory, EntityTreeNodeMapperInterface $mapper, EntityFieldManagerInterface $entity_field_manager) {
    $this->storageFactory = $storage_factory;
    $this->nodeKeyFactory = $node_key_factory;
    $this->mapper = $mapper;
    $this->entityFieldManager = $entity_field_manager;
  }

  public function getAncestors(ContentEntityInterface $entity) {
    $entity_type = $entity->getEntityTypeId();
    $storage = $this->storageFactory->get($this->getHierarchyFieldFromEntity($entity), $entity_type);
    $ancestors = $storage->findAncestors($this->nodeKeyFactory->fromEntity($entity));

    $ancestor_entities = $this->mapper->loadAndAccessCheckEntitysForTreeNodes($entity_type, $ancestors);

    $ancestors = [];
    foreach ($ancestor_entities as $ancestor_entity) {
      if (!$ancestor_entities->contains($ancestor_entity)) {
        // Doesn't exist or is access hidden.
        continue;
      }

      $ancestor = $ancestor_entities->offsetGet($ancestor_entity);
      if ($ancestor->id() == $entity->id()) {
        continue;
      }

      // Show just the label for the entity from the route.
      $ancestors[] = $ancestor;
    }

    return $ancestors;
  }

  /**
   * Gets the field name to use for the hierarchy.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   *
   * @return string|null
   *   The field name, or null if there are no fields to use.
   */
  protected function getHierarchyFieldFromEntity(ContentEntityInterface $entity) {
    $fields = $this->entityFieldManager->getFieldMapByFieldType('entity_reference_hierarchy');
    $entity_type = $entity->getEntityTypeId();
    if (isset($fields[$entity_type])) {
      foreach ($fields[$entity_type] as $field_name => $detail) {
        if (!empty($detail['bundles'][$entity->bundle()])) {
          return $field_name;
        }
      }
    }

    return NULL;
  }

  public function getChildrenOfParent(ContentEntityInterface $entity) {
    // TODO
  }

  /**
   * @param int $parent_id
   *
   * @return ContentEntityInterface[]
   */
  public function getChildrenOfParentID($parent_id) {
    $node_ids = \Drupal::database()
      ->select('node__field_parent', 't')
      ->fields('t', ['entity_id'])
      ->condition('t.field_parent_target_id', $parent_id)
      ->orderBy('t.field_parent_weight', 'ASC')
      ->execute()
      ->fetchCol();

    if (!empty($node_ids)) {
      $nodes = [];
      /** @var Node $node */
      foreach (Node::loadMultiple($node_ids) as $node) {
        if($node->access()) {
          $nodes[] = $node;
        }
      }
      return $nodes;
    } else {
      return [];
    }
  }
}
