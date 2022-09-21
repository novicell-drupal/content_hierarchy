<?php

namespace Drupal\content_hierarchy\Plugin\Transform\Field;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\transform_api\FieldTransformBase;

/**
 * @FieldTransform(
 *  id = "content_hierarchy_children",
 *  title = "Content Hierarchy children",
 *  description = "Renders the children of the referenced entity.",
 *  types = {
 *    "entity_reference"
 *  }
 * )
 */
class ContentHierarchyChildren extends FieldTransformBase {

  public function transformElements(FieldItemListInterface $items, $langcode) {
    $values = [];
    $entity_type_id = $items->getSetting('target_type');
    $view_mode = $this->getSetting('view_mode');
    /** @var FieldItemInterface $item */
    foreach ($items as $delta => $item) {
      $transforms = [];
      if (!empty($item->getValue()['target_id'])) {
        $entity = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($item->getValue()['target_id']);
        /** @var \Drupal\content_hierarchy\ContentHierarchyStorage $contentHierarchyStorage */
        $contentHierarchyStorage = \Drupal::service('content_hierarchy.storage');
        $content = $contentHierarchyStorage->loadFromEntity($entity);

        if (!is_null($content)) {
          $children = $content->getChildren() ?? [];
          foreach ($children as $child) {
            $transforms[] = new \Drupal\transform_api\Transform\EntityTransform($child->getEntityType(), $child->getEntityId(), $view_mode);
          }

          // Set a cache tags, so it is possible to invalidate it,
          // if a new node sets the selected parent as it´s parent.
          $transforms['#cache']['tags'] = $contentHierarchyStorage->getContentCacheTags([$content]);
        }
      }
      $values[$delta] = $transforms;
    }
    return $values;
  }

}
