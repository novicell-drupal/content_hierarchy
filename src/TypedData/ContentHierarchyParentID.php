<?php
namespace Drupal\content_hierarchy\TypedData;

use Drupal;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\TypedData\TypedData;

class ContentHierarchyParentID extends TypedData {
  /**
   * Cached processed value.
   *
   * @var string|null
   */
  protected $processed = NULL;

  /**
   * Implements \Drupal\Core\TypedData\TypedDataInterface::getValue().
   */
  public function getValue($langcode = NULL) {
    if ($this->processed !== NULL) {
      return $this->processed;
    }
    /** @var \Drupal\content_hierarchy\ContentHierarchyData $data */
    $data = Drupal::service('content_hierarchy.data');

    /** @var FieldItemInterface $field */
    $field = $this->parent;
    $entity = $field->getEntity();
    $content_id = $data->findEntity($entity);
    $content = $data->getContent($content_id);

    $this->processed = $content['parent_id'];
    return $this->processed;
  }

  /**
   * Implements \Drupal\Core\TypedData\TypedDataInterface::setValue().
   */
  public function setValue($value, $notify = TRUE) {
    $this->processed = $value;

    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

}
