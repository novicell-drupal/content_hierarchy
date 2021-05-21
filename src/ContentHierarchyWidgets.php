<?php
namespace Drupal\content_hierarchy;

use Drupal\Core\StringTranslation\StringTranslationTrait;

class ContentHierarchyWidgets {

  use StringTranslationTrait;

  /**
   * Content Hierarchy storage service
   *
   * @var \Drupal\content_hierarchy\ContentHierarchyStorage
   */
  protected $storage;

  /**
   * Content Hierarchy storage service
   *
   * @var \Drupal\content_hierarchy\ContentHierarchyData
   */
  protected $data;

  public function __construct(ContentHierarchyStorage $storage, ContentHierarchyData $data) {
    $this->storage = $storage;
    $this->data = $data;
  }

  /**
   * @param $level
   * @param ContentHierarchy[] $items
   *
   * @return array|mixed
   */
  protected function generateHierarchyTree($level, array $items) {
    $options = [];
    $prefix = str_repeat('--', $level);
    foreach ($items as $item) {
      $options[$item->id()] = $prefix . $item->label();
      if (!empty($item->getChildren())) {
        $options += $this->generateHierarchyTree($level + 1, $item->getChildren());
      }
    }
    return $options;
  }

  /**
   * Returns the array of options for the widget.
   *
   * @param string $langcode
   *
   * @return array
   *   The array of options for the widget.
   */
  public function getAllOptions() {
    $options = [-1 => ' - ' . $this->t('Exclude') . ' - '];
    $options += [0 => ' - ' . $this->t('Root') . ' - '];
    foreach ($this->data->getAllContentIDs() as $contentID) {
      $options[$contentID] = ' ';
    }

    return $options;
  }

  /**
   * @param \Drupal\content_hierarchy\ContentHierarchy $content
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   */
  public function placementToText(ContentHierarchy $content) {
    $placement = $content->getPlacement();
    switch ($placement) {
      case -1:
        return $this->t('Exclude');
      case 0:
        return $this->t('Root');
      default:
        $ancestors = $this->storage->findAncestors($content);
        $ancestors[$content->id()] = $content;
        $result = '';
        foreach ($ancestors as $ancestor) {
          if (!empty($result)) {
            $result .= ' -> ';
          }
          $result .= $ancestor->label();
        }
        return $result;
        break;
    }
  }

  /**
   * @param string|null $langcode
   * @param int $placement
   *
   * @return array
   */
  public function getRenderableOptions($langcode = NULL, $placement = -1) {
    $items = [];
    $items[-1] = [
      'key' => -1,
      'value' => $this->t('Exclude'),
      'prefix' => ' - ',
      'suffix' => ' - ',
      'selected' => ''
    ];
    $items[0] = [
      'key' => 0,
      'value' => $this->t('Root'),
      'prefix' => ' - ',
      'suffix' => ' - ',
      'selected' => ''
    ];

    foreach ($this->generateHierarchyTree(0, $this->storage->getTree($langcode)) as $key => $value) {
      $items[intval($key)] = [
        'key' => $key,
        'value' => $value,
        'prefix' => '',
        'suffix' => '',
        'selected' => ''
      ];
    }
    if (isset($items[intval($placement)])) {
      $items[intval($placement)]['selected'] = ' selected';
    }

    return $items;
  }
}
