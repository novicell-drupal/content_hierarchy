<?php
namespace Drupal\content_hierarchy;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

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

  /**
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  public function __construct(ContentHierarchyStorage $storage, EntityTypeManagerInterface $entityTypeManager, EntityTypeBundleInfoInterface $entityTypeBundleInfo, ContentHierarchyData $data, RendererInterface $renderer) {
    $this->storage = $storage;
    $this->data = $data;
    $this->entityTypeManager = $entityTypeManager;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
    $this->renderer = $renderer;
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
   * @param int $placement
   * @param string|null $langcode
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   */
  public function placementToText($placement, $langcode = NULL) {
    switch ($placement) {
      case -1:
        return $this->t('Excluded');
      case 0:
        return $this->t('Root');
      default:
        $content = $this->storage->load($placement, $langcode);
        $ancestors = $this->storage->findAncestors($content);
        $ancestors[$content->id()] = $content;
        $result = '';
        foreach ($ancestors as $ancestor) {
          if (!empty($result)) {
            $result .= ' / ';
          }
          $result .= $ancestor->label();
        }
        return $result;
    }
  }

  /**
   * @param int $placement
   * @param string|null $langcode
   *
   * @return array
   */
  public function buildPlacement($placement, $langcode = NULL) {
    switch ($placement) {
      case -1:
        return ['#markup' => '<i>' . $this->t('Excluded') . '</i>'];
      case 0:
        return ['#markup' => '<i>' . $this->t('Root') . '</i>'];
      default:
        $content = $this->storage->load($placement, $langcode);
        $ancestors = $this->storage->findAncestors($content);
        $ancestors[$content->id()] = $content;
        $result = [];
        foreach ($ancestors as $ancestor) {
          if (!empty($result)) {
            $result[] = ['#markup' => ' / '];
          }
          $result[] = Link::fromTextAndUrl($ancestor->label(), $ancestor->getUrl())->toRenderable();
        }
        return $result;
    }
  }

  /**
   * @param string $langcode
   * @param int|null $content_id
   *
   * @return \Drupal\content_hierarchy\ContentHierarchy[]
   */
  protected function getValidPlacementOptions($langcode, $content_id = NULL) {
    static $options = [];
    if (!isset($options[$langcode])) {
      $options[$langcode] = [];
    }

    if (!isset($options[$langcode][$content_id ?? 0])) {
      $items = [];
      $excluded = [];
      if (!empty($content_id)) {
        $excluded = $this->data->getChildrenOf($content_id, $langcode);
        $excluded[] = $content_id;
      }
      foreach ($this->storage->getListWithDepth($langcode) as $item) {
        if (in_array($item->id(), $excluded)) {
          continue;
        }
        $items[$item->id()] = $item;
      }
      $options[$langcode][$content_id ?? 0] = $items;
    }

    return $options[$langcode][$content_id ?? 0];
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
    if (!is_null($content_id)) {
      $content = $this->storage->load($content_id, $langcode);
      $placement = $content->getPlacement();
    }
    foreach ($this->getValidPlacementOptions($langcode, $content_id) as $key => $item) {
      $items[intval($key)] = [
        'key' => $key,
        'value' => $item->label(),
        'prefix' => str_repeat('--', $item->getDepth()),
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
   * @param string $langcode
   * @param int|null $content_id
   * @param int $value
   *
   * @return array
   */
  public function buildModalWidget($langcode, $content_id = NULL, $placement = -1) {
    $element = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['content-hierarchy-modal__widget'],
        'data-content-id' => $content_id ?? 0
      ]
    ];

    $element['placement'] = [
      '#type' => 'item',
      '#title' => $this->t('Placement'),
    ];
    $element['placement'][] = $this->buildPlacement($placement, $langcode);
    $element['placement']['change'] = [
      '#title' => $this->t('Change'),
      '#type' => 'link',
      '#url' => Url::fromRoute('content_hierarchy.modal.form', [], ['query' => ['langcode' => $langcode, 'content_id' => $content_id ?? 0]]),
      '#attributes' => [
        'class' => ['use-ajax', 'button', 'button--small', 'add'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode([
          'width' => 700,
        ]),
      ],
    ];

    return $element;
  }

  /**
   * @param \Drupal\content_hierarchy\ContentHierarchy $content
   * @param string $wrapper
   * @param string $langcode
   * @param string|null $content_id
   * @param bool $includeChildren
   *
   * @return array
   */
  public function buildModalItem(ContentHierarchy $content, $langcode, $content_id = NULL, $includeChildren = FALSE) {
    $build = [
      '#theme' => 'content_hierarchy_modal_item',
      '#id' => $content->id(),
      '#content_id' => $content_id,
      '#langcode' => $langcode,
      '#content' => $content,
      '#children' => []
    ];
    if ($includeChildren) {
      $items = $this->getValidPlacementOptions($langcode, $content_id);
      foreach ($items as $key => $item) {
        if ($item->getParentId() == $content->id()) {
          $build['#children'][$key] = $this->buildModalItem($item, $langcode, $content_id);
        }
      }
      if (!empty($build['#children'])) {
        $build['#children'] = $this->renderer->renderPlain($build['#children']);
      }
    }
    return $build;
  }

  /**
   * @param string|null $langcode
   * @param int|null $content_id
   *
   * @return array
   */
  public function getInitialModalItems($langcode, $content_id = NULL) {
    $items = $this->getValidPlacementOptions($langcode, $content_id);
    $build = [];
    foreach ($items as $item) {
      if ($item->getDepth() > 0) {
        continue;
      }
      $build[$item->id()] = $this->buildModalItem($item, $langcode, $content_id, TRUE);
    }
    return $build;
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
