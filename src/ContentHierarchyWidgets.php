<?php
namespace Drupal\content_hierarchy;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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

  public function __construct(ContentHierarchyStorage $storage, EntityTypeManagerInterface $entityTypeManager, EntityTypeBundleInfoInterface $entityTypeBundleInfo, ContentHierarchyData $data) {
    $this->storage = $storage;
    $this->data = $data;
    $this->entityTypeManager = $entityTypeManager;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
  }

  /**
   * @param $level
   * @param ContentHierarchy[] $items
   *
   * @return array|mixed
   */
  protected function generateHierarchyTree($level, array $items, array $excluded = []) {
    $options = [];
    $prefix = str_repeat('--', $level);
    foreach ($items as $item) {
      if (in_array($item->id(), $excluded)) {
        continue;
      }
      $options[$item->id()] = $prefix . $item->label();
      if (!empty($item->getChildren())) {
        $options += $this->generateHierarchyTree($level + 1, $item->getChildren(), $excluded);
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
  public function getAllOptions($langcode = NULL) {
    $options = [-1 => ' - ' . $this->t('Exclude') . ' - '];
    $options += [0 => ' - ' . $this->t('Root') . ' - '];
    foreach ($this->storage->getListWithDepth($langcode) as $item) {
      $options[$item->id()] = str_repeat('--', $item->getDepth()) . $item->getTitle();
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
  public function getRenderableOptions($langcode = NULL, $content_id = NULL) {
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

    $placement = -1;
    $excluded = [];
    if (!is_null($content_id)) {
      $content = $this->storage->load($content_id, $langcode);
      $placement = $content->getPlacement();
      $excluded = $this->data->getChildrenOf($content_id, $langcode);
      $excluded[] = $content_id;
    }
    foreach ($this->generateHierarchyTree(0, $this->storage->getTree($langcode), $excluded) as $key => $value) {
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

  /**
   * @return array
   */
  public function getSupportedEntityTypes() {
    $types = [];
    $entity_types = $this->entityTypeManager->getDefinitions();
    foreach ($entity_types as $entity_type) {
      if (!$this->canBePutInHierarchy($entity_type)) {
        continue;
      }

      $bundles = [];
      foreach ($this->entityTypeBundleInfo->getBundleInfo($entity_type->id()) as $bundle_id => $bundle) {
        $bundles[$bundle_id] = [
          'id' => $bundle_id,
          'label' => $bundle['label']
        ];
      }

      $types[$entity_type->id()] = [
        'id' => $entity_type->id(),
        'label' => $entity_type->getLabel(),
        'bundles' => $bundles,
      ];
    }
    \Drupal::moduleHandler()->alter('content_hierarchy_entity_types', $types);

    return $types;
  }

  /**
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *
   * @return bool
   */
  protected function canBePutInHierarchy(EntityTypeInterface $entity_type): bool {
    if (!$entity_type->hasViewBuilderClass() || !$entity_type->isCommonReferenceTarget() || !$entity_type->hasRouteProviders() || $entity_type->getBundleEntityType() == NULL) {
      return false;
    }
    return true;
  }

}
