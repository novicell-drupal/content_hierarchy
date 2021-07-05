<?php
namespace Drupal\content_hierarchy;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandler;

class ContentHierarchyStorage {

  /**
   * Content Hierarchy data service
   *
   * @var \Drupal\content_hierarchy\ContentHierarchyData
   */
  protected $data;

  /**
   * Content Hierarchy cache bin
   *
   * @var CacheBackendInterface
   */
  protected $cache;

  /**
   * Entity type manager service
   *
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * AccountPermissionsCacheContext service
   *
   * @var CacheContextInterface
   */
  protected $cacheContext;

  /**
   * Module handler.
   *
   * @var ModuleHandler
   */
  protected $moduleHandler;

  /**
   * ContentHierarchyStorage constructor.
   *
   * @param \Drupal\content_hierarchy\ContentHierarchyData $data
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(ContentHierarchyData $data, CacheBackendInterface $cache, EntityTypeManagerInterface $entityTypeManager, CacheContextInterface $cacheContext, ModuleHandler $moduleHandler) {
    $this->data = $data;
    $this->cache = $cache;
    $this->entityTypeManager = $entityTypeManager;
    $this->cacheContext = $cacheContext;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * @param int $id
   * @param string|null $langcode
   *
   * @return ContentHierarchy|null
   */
  public function load($id, $langcode = NULL) {
    $result = $this->loadMultiple([$id], $langcode);
    if (!empty($result[$id])) {
      return $result[$id];
    } else {
      return NULL;
    }
  }

  /**
   * @param int[] $ids
   * @param string|null $langcode
   *
   * @return ContentHierarchy[]
   */
  public function loadMultiple(array $ids, $langcode = NULL) {
    $result = [];
    foreach ($ids as $content_id) {
      $content = $this->data->getContentAndPlacement($content_id, $langcode);
      if ($content !== FALSE) {
        $this->populateContent($content);
        $result[$content_id] = new ContentHierarchy($content);
      }
    }
    return $result;
  }

  /**
   * @param EntityInterface $entity
   *
   * @return ContentHierarchy|null
   */
  public function loadFromEntity($entity) {
    $content_id = $this->data->findEntity($entity);
    if (is_null($content_id)) {
      return NULL;
    }
    return $this->load($content_id, $entity->language()->getId());
  }

  /**
   * @param \Drupal\content_hierarchy\ContentHierarchy $content
   *
   * @return \Drupal\content_hierarchy\ContentHierarchy[]
   */
  public function findAncestors(ContentHierarchy $content) {
    $ancestors = [];
    while($content->getParentId() > 0) {
      $content = $this->load($content->getParentId(), $content->getLangcode());
      $ancestors[$content->id()] = $content;
    }
    return array_reverse($ancestors);
  }

  /**
   * @param \Drupal\content_hierarchy\ContentHierarchy $content
   *
   * @return \Drupal\content_hierarchy\ContentHierarchy[]
   */
  public function findChildren(ContentHierarchy $content) {
    return $this->loadMultiple($this->data->getChildrenOf($content->id(), $content->getLangcode(), FALSE), $content->getLangcode());
  }

  /**
   * @param string|null $langcode
   * @param bool $allow_excluded
   *
   * @return ContentHierarchy[]
   */
  public function getListWithDepth($langcode = NULL, $allow_excluded = FALSE) {
    $tree = $this->getTree($langcode, $allow_excluded);
    $items = [];
    foreach ($tree as $key => $item) {
      $items[] = $item;
      if (!empty($item->getChildren())) {
        $this->addChildrenToList($items, $item->getChildren());
      }
    }
    return $items;
  }

  /**
   * @param ContentHierarchy[] $items
   * @param ContentHierarchy[] $children
   */
  protected function addChildrenToList(array &$items, array $children) {
    foreach ($children as $key => $item) {
      $items[] = $item;
      if (!empty($item->getChildren())) {
        $this->addChildrenToList($items, $item->getChildren());
      }
    }
  }

  /**
   * @param string|null $langcode
   * @param bool $allow_excluded
   *
   * @return ContentHierarchy[]
   */
  public function getTree($langcode = NULL, $allow_excluded = FALSE) {
    $items = $this->data->getLanguageTree($langcode, $allow_excluded);
    $this->populateTree($items);

    $tree = [];
    foreach ($items as $key => $item) {
      $tree[$key] = new ContentHierarchy($item);
    }

    return $tree;
  }

  /**
   * @param array $items
   *
   * @return array
   */
  protected function populateTree(&$items) {
    foreach ($items as $key => $item) {
      $this->populateContent($item);
      $items[$key] = $item;
      if (!empty($item['children'])) {
        $this->populateTree($items[$key]['children']);
      }
    }
    return $items;
  }

  public function populateContent(array &$content) {
    $contents = &drupal_static(__FUNCTION__, []);

    if (empty($content) || empty($content['content_id'])) {
      return;
    }

    $content_id = $content['content_id'];
    $cid = 'content:' . $content_id . ':' . $content['langcode'] . ':' . $this->cacheContext->getContext();
    if (empty($contents[$cid])) {
      if ($cache = $this->cache->get($cid)) {
        $contents[$cid] = $cache->data;
      } else {
        $cacheMetadata = new CacheableMetadata();
        if ($content['source'] == 'entity') {
          /** @var ContentEntityInterface $entity */
          $entity = $this->entityTypeManager->getStorage($content['type'])->load($content['entity_id']);
          if (is_null($entity)) {
            $this->data->deleteContent($content_id);
            return;
          }
          $entity = $entity->getTranslation($content['langcode']);
          $cacheMetadata->addCacheableDependency($entity);

          $contents[$cid]['title'] = $entity->label();
          $contents[$cid]['entity_id'] = $entity->id();
          $contents[$cid]['changed'] = $entity->get('changed')->first()->getValue()['value'] ?? NULL;
          $contents[$cid]['created'] = $entity->get('created')->first()->getValue()['value'] ?? NULL;
          $contents[$cid]['entity_type'] = $entity->getEntityTypeId();
          $contents[$cid]['entity_bundle'] = $entity->bundle();
          $status = $entity->get('status')->first()->getValue()['value'] ?? NULL;
          if ($status === '1') {
            $contents[$cid]['status'] = 'Published';
          } elseif ($status === '0') {
            $contents[$cid]['status'] = 'Unpublished';
          }
          try {
            $contents[$cid]['url'] = $entity->toUrl();
          } catch (EntityMalformedException $e) {
            $contents[$cid]['url'] = NULL;
          }
          $contents[$cid]['operations'] = $this->entityTypeManager->getListBuilder($content['type'])->getOperations($entity);
          foreach ($contents[$cid]['operations'] as $key => $operation) {
            $contents[$cid]['operations'][$key]['url']->setOption('query', []);
          }
        }
        $this->cache->set($cid, $contents[$cid], $cacheMetadata->getCacheMaxAge(), $cacheMetadata->getCacheTags());
      }
    }
    if (isset($contents[$cid])) {
      $this->moduleHandler->alter('content_hierarchy_populate_content', $contents[$cid]);
      $content += $contents[$cid];
    }
  }

  /**
   * @param string|null $langcode
   *
   * @return string[]
   */
  public function getListCacheTags($langcode = NULL) {
    $config = \Drupal::config('content_hierarchy.hierarchy_settings');
    $langcode = $this->data->correctLangCode($langcode);
    $tags = [
      'content_hierarchy_list:' . $langcode
    ];
    foreach ($config->get('entity_bundles') ?? [] as $entity_type => $bundle) {
      $tags[] = $entity_type . '_list';
    }
    return $tags;
  }

  /**
   * @param ContentHierarchy[] $contents
   */
  public function getContentCacheTags(array $contents) {
    $langcodes = [];
    $contentIDs = [];
    $placements = [];
    foreach ($contents as $content) {
      $langcodes[$content->getLangcode()] = $content->getLangcode();
      $contentIDs[$content->id()] = $content->id();
      foreach ($this->data->getChildrenOf($content->id(), $content->getLangcode(), TRUE) as $contentID) {
        $placements[$contentID . ':' . $content->getLangcode()] = $contentID . ':' . $content->getLangcode();
      }
      foreach ($this->data->getAncestorsOf($content->id(), $content->getLangcode()) as $contentID) {
        $placements[$contentID . ':' . $content->getLangcode()] = $contentID . ':' . $content->getLangcode();
      }
    }
    $tags = [];
    foreach ($langcodes as $langcode) {
      $tags[] = 'content_hierarchy_list:' . $langcode;
    }
    foreach ($contentIDs as $contentID) {
      $tags[] = 'content_hierarchy:' . $contentID;
    }
    foreach ($placements as $placement) {
      $tags[] = 'content_hierarchy_placement:' . $placement;
    }
    return $tags;
  }

  /**
   * Check if an entity is part of the Content Hierarchy tree
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return bool
   */
  public function isEntityInHierarchy(EntityInterface $entity) {
    if ($entity instanceof ContentEntityInterface && $entity->hasField('content_hierarchy')) {
      $content_id = $this->data->findEntity($entity);
      if (!is_null($content_id)) {
        $placement = $this->data->getContentPlacement($content_id, $entity->language()->getId());
        if (is_int($placement) && $placement > -1) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Notify Content Hierarchy that an entity has changed
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   */
  public function deleteEntityFromHierarchy(EntityInterface $entity) {
    $content = $this->loadFromEntity($entity);
    if (empty($content)) {
      return;
    }
    $contents = [$content];
    $children = $this->loadMultiple($this->data->getChildrenOf($content->id()));
    foreach ($children as $child) {
      $this->data->deleteContent($child->id());
      $contents[] = $child;
    }
    Cache::invalidateTags($this->getContentCacheTags($contents));
    $this->data->deleteContent($content->id());
  }

  public function save(ContentHierarchy $content) {
    $this->saveMultiple([$content]);
  }

  /**
   * @param ContentHierarchy[] $contents
   */
  public function saveMultiple(array $contents) {
    $langcodes = [];
    $contentIDs = [];
    $placements = [];
    foreach ($contents as $content) {
      $langcodes[$content->getLangcode()] = $content->getLangcode();
      $contentIDs[$content->id()] = $content->id();
      foreach ($this->data->getChildrenOf($content->id(), $content->getLangcode(), TRUE) as $contentID) {
        $placements[$contentID . ':' . $content->getLangcode()] = $contentID . ':' . $content->getLangcode();
      }
      foreach ($this->data->getAncestorsOf($content->id(), $content->getLangcode()) as $contentID) {
        $placements[$contentID . ':' . $content->getLangcode()] = $contentID . ':' . $content->getLangcode();
      }
    }
    $tags = [];
    foreach ($langcodes as $langcode) {
      $tags[] = 'content_hierarchy_list:' . $langcode;
    }
    foreach ($contentIDs as $contentID) {
      $tags[] = 'content_hierarchy:' . $contentID;
    }
    foreach ($placements as $placement) {
      $tags[] = 'content_hierarchy_placement:' . $placement;
    }
    foreach ($contents as $content) {
      $this->data->setContentPlacement($content->id(), $content->getLangcode(), $content->getPlacement(), $content->getWeight());
    }
    Cache::invalidateTags($tags);
  }

}
