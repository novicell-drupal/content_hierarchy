<?php
namespace Drupal\content_hierarchy;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Language\LanguageInterface;
use PDO;

class ContentHierarchyData {
  protected $database;

  /**
   * Content Hierarchy cache bin
   *
   * @var CacheBackendInterface
   */
  protected $cache;

  public function __construct(Connection $database, CacheBackendInterface $cache) {
    $this->database = $database;
    $this->cache = $cache;
  }

  /**
   * @param string|null $langcode
   *
   * @return array
   */
  public function getLanguageList($langcode = NULL, $allow_excluded = FALSE) {
    $lists = &drupal_static(__FUNCTION__, []);

    $cid = 'list:' . $langcode;
    if (empty($lists[$cid])) {
      if ($cache = $this->cache->get($cid)) {
        $lists[$cid] = $cache->data;
      }
      else {
        $langcode = $this->correctLangCode($langcode);
        $query = $this->database->select('content_hierarchy', 'ch')
          ->fields('ch');
        $alias = $query->innerJoin('content_hierarchy_placement', 'chp', "ch.content_id = %alias.content_id AND %alias.langcode = '" . $langcode . "'");
        $lists[$cid] = $query
          ->fields($alias)
          ->orderBy($alias . '.weight', 'ASC')
          ->execute()
          ->fetchAllAssoc('content_id', PDO::FETCH_ASSOC);

        $this->cache->set($cid, $lists[$cid], Cache::PERMANENT, ['content_hierarchy_list:' . $langcode]);
      }
    }
    $items = $lists[$cid];

    if (!$allow_excluded) {
      foreach ($items as $content_id => $item) {
        if ($item['excluded'] == 1) {
          unset($items[$content_id]);
        }
      }
    }

    return $items;
  }

  /**
   * @param string|null $langcode
   *
   * @return array
   */
  public function getLanguageTree($langcode = null, $allow_excluded = FALSE) {
    $trees = &drupal_static(__FUNCTION__, []);

    $cid = 'tree:' . $langcode . ':' . intval($allow_excluded);
    if (empty($trees[$cid])) {
      if ($cache = $this->cache->get($cid)) {
        $trees[$cid] = $cache->data;
      }
      else {
        $items = $this->getLanguageList($langcode, $allow_excluded);
        $parents = [];
        foreach ($items as $content_id => $item) {
          $items[$content_id]['children'] = [];
          $items[$content_id]['depth'] = 0;
          $parents[$item['parent_id']] = $item['parent_id'];
        }

        while(count($parents) > 1) {
          foreach ($items as $content_id => $item) {
            if (!in_array($content_id, $parents) && $item['parent_id'] > 0) {
              $items[$item['parent_id']]['children'][$content_id] = $item;
              $this->increaseDepthInTree($items[$item['parent_id']]['children'][$content_id]);
              unset($items[$content_id]);
            }
          }
          $parents = [];
          foreach ($items as $item) {
            $parents[$item['parent_id']] = $item['parent_id'];
          }
        }
        $trees[$cid] = $items;
        $this->cache->set($cid, $trees[$cid], Cache::PERMANENT, ['content_hierarchy_list:' . $langcode]);
      }
    }

    return $trees[$cid];
  }

  /**
   * @param string|null $langcode
   *
   * @return array
   */
  public function getLanguageListWithDepth($langcode = NULL, $open_items = []) {
    $langcode = $this->correctLangCode($langcode);
    $parents = $this->database->select('content_hierarchy_placement', 'chp')
      ->fields('chp', ['content_id'])
      ->condition('langcode', $langcode)
      ->condition('parent_id', array_keys($open_items), 'IN')
      ->condition('excluded', 0)
      ->execute()
      ->fetchCol();
    $parents[] = 0;

    $query = $this->database->select('content_hierarchy', 'ch')
      ->fields('ch');
    $alias = $query->innerJoin('content_hierarchy_placement', 'chp', "ch.content_id = %alias.content_id AND %alias.langcode = '" . $langcode . "'");
    $items = $query
      ->fields($alias)
      ->condition($alias . '.parent_id', $parents, 'IN')
      ->condition($alias . '.excluded', 0)
      ->orderBy($alias . '.weight', 'ASC')
      ->orderBy($alias . '.content_id', 'ASC')
      ->execute()
      ->fetchAllAssoc('content_id', PDO::FETCH_ASSOC);

    $roots = [];
    foreach ($items as $key => $item) {
      $parent = $item['parent_id'];
      if ($parent == 0) {
        $roots[] = $key;
      } else {
        if (empty($items[$parent]['children'])) {
          $items[$parent]['children'] = [];
        }
        $items[$parent]['children'][] = $key;
      }
    }
    $content = [];
    foreach ($roots as $root) {
      $items[$root]['depth'] = 0;
      $content[] = $items[$root];
      if (!empty($items[$root]['children'])) {
        foreach ($items[$root]['children'] as $child_id) {
          $items[$child_id]['depth'] = 1;
          $content[] = $items[$child_id];
          if (!empty($items[$child_id]['children'])) {
            $this->addLanguageListWithDepth($content, $items, $items[$child_id]['children']);
          }
        }
      }
    }
    return $content;
  }

  /**
   * @param string|null $langcode
   *
   * @return array
   */
  protected function addLanguageListWithDepth(&$content, $items, $children, $depth = 2) {
    foreach ($children as $child_id) {
      $item = $items[$child_id];
      $item['depth'] = $depth;
      $content[] = $item;
      if (!empty($items[$child_id]['children'])) {
        $this->addLanguageListWithDepth($content, $items, $items[$child_id]['children'], $depth + 1);
      }
    }
  }

    /**
   * @param array $item
   */
  protected function increaseDepthInTree(array &$item) {
    $item['depth']++;
    if (!empty($item['children'])) {
      foreach ($item['children'] as $key => $child) {
        $this->increaseDepthInTree($item['children'][$key]);
      }
    }
  }

  /**
   * @param EntityInterface $entity
   *
   * @return int|null
   */
  public function findEntity(EntityInterface $entity) {
    return $this->findContentID('entity', $entity->getEntityTypeId(), $entity->id());
  }

  /**
   * @param string $source
   * @param string $type
   * @param string|int|null $entity_id
   *
   * @return int|null
   */
  public function findContentID($source, $type, $entity_id = NULL) {
    if (is_null($entity_id)) {
      return NULL;
    } else {
      $query = $this->database->select('content_hierarchy', 'ch')
        ->fields('ch', ['content_id'])
        ->condition('source', $source)
        ->condition('type', $type);
      if (!empty($entity_id)) {
        $query->condition('entity_id', $entity_id);
      }
      $result = $query->execute()->fetchField();
      if (empty($result)) {
        return NULL;
      } else {
        return $result;
      }
    }
  }

  /**
   * @param string $entity_type
   * @param array $entity_ids
   *
   * @return array
   */
  public function findEntityIds($entity_type, array $entity_ids) {
    $query = $this->database->select('content_hierarchy', 'ch')
      ->fields('ch', ['content_id'])
      ->condition('source', 'entity')
      ->condition('type', $entity_type)
      ->condition('entity_id', $entity_ids, 'IN');
    return $query->execute()->fetchCol();
  }

  /**
   * @param int $content_id
   *
   * @return array|bool
   */
  public function getContent($content_id) {
    return $this->database->select('content_hierarchy', 'ch')
      ->condition('content_id', $content_id)
      ->fields('ch')
      ->execute()
      ->fetchAssoc();
  }

  /**
   * @param int $content_id
   *
   * @return array|bool
   */
  public function getContentAndPlacement($content_id, $langcode = NULL) {
    $langcode = $this->correctLangCode($langcode);
    $content = $this->getContent($content_id);
    if ($content !== FALSE) {
      $extra = $this->database->select('content_hierarchy_placement', 'chp')
        ->condition('content_id', $content_id)
        ->condition('langcode', $langcode)
        ->fields('chp')
        ->execute()
        ->fetchAssoc();
      if ($extra !== FALSE) {
        $content += $extra;
      }
    }
    return $content;
  }
  /**
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   * @param int $placement
   * @param int|null $weight
   */
  public function setEntityPlacement(FieldableEntityInterface $entity, $placement, $weight = NULL) {
    if (empty($entity->id())) {
      return;
    }
    $content_id = $this->findEntity($entity);
    if (empty($content_id)) {
      $content_id = $this->addContent('entity', $entity->getEntityTypeId(), $entity->id());
    }
    $this->setContentPlacement($content_id, $entity->language()->getId(), $placement, $weight);
  }

  /**
   * @param int $parent_id
   * @param string $source
   * @param string $type
   * @param string|int|null $entity_id
   * @param string $langcode
   *
   * @return int
   */
  public function addContent($source, $type, $entity_id) {
    $values = [
      'source' => $source,
      'type' => $type,
      'entity_id' => $entity_id
    ];
    $content_id = $this->database->insert('content_hierarchy')
      ->fields($values)
      ->execute();

    return $content_id;
  }

  /**
   * @param int $content_id
   */
  public function deleteContent($content_id) {
    $this->database->delete('content_hierarchy')
      ->condition('content_id', $content_id)
      ->execute();
    $this->database->delete('content_hierarchy_placement')
      ->condition('content_id', $content_id)
      ->execute();
  }

  /**
   * @param array $content_ids
   */
  public function deleteMultiple(array $content_ids) {
    $this->database->delete('content_hierarchy')
      ->condition('content_id', $content_ids, 'IN')
      ->execute();
    $this->database->delete('content_hierarchy_placement')
      ->condition('content_id', $content_ids, 'IN')
      ->execute();
  }

  /**
   * @param int $content_id
   * @param string|null $langcode
   *
   * @return int|null
   */
  public function getContentPlacement($content_id, $langcode = NULL) {
    $langcode = $this->correctLangCode($langcode);
    $result = $this->database->select('content_hierarchy_placement', 'chp')
      ->condition('content_id', $content_id)
      ->condition('langcode', $langcode)
      ->fields('chp')
      ->execute()
      ->fetchAssoc();

    if (empty($result)) {
      return NULL;
    } else {
      $placement = intval($result['parent_id']);
      if ($result['excluded'] == 1) {
        $placement = -1;
      }
    }
    return $placement;
  }

  /**
   * @param int $content_id
   * @param string $langcode
   * @param int $placement
   * @param int|null $weight
   */
  public function setContentPlacement($content_id, $langcode, $placement, $weight = NULL) {
    $langcode = $this->correctLangCode($langcode);
    $values = $this->placementToValues($placement);

    $current = $this->getContentPlacement($content_id, $langcode);

    if (is_null($current)) {
      $values['content_id'] = $content_id;
      $values['langcode'] = $langcode;
      $this->database->insert('content_hierarchy_placement')
        ->fields($values)
        ->execute();
    } else {
      $this->database->update('content_hierarchy_placement')
        ->fields($values)
        ->condition('content_id', $content_id)
        ->condition('langcode', $langcode)
        ->execute();
    }
    if (!is_null($weight)) {
      $this->database->update('content_hierarchy_placement')
        ->fields(['weight' => $weight])
        ->condition('content_id', $content_id)
        ->condition('langcode', $langcode)
        ->execute();
    }
  }

  public function getAncestorsOf($content_id, $langcode = NULL, &$ancestors = []) {
    $placement = $this->getContentPlacement($content_id, $langcode);
    if ($placement > 0) {
      $ancestors[] = $placement;
      $this->getAncestorsOf($placement, $langcode, $ancestors);
    }
    return array_reverse($ancestors);
  }

  /**
   * @param $content_id
   * @param string|null $langcode
   * @param bool $recursive
   *
   * @return array
   */
  public function getChildrenOf($content_id, $langcode = NULL, $recursive = TRUE) {
    $langcode = $this->correctLangCode($langcode);
    $tree = $this->getLanguageTree($langcode);
    $ancestors = $this->getAncestorsOf($content_id, $langcode);
    $children = [];
    foreach ($ancestors as $contentID) {
      $tree = $tree[$contentID]['children'];
    }
    if (isset($tree[$content_id])) {
      $content = $tree[$content_id];
      foreach ($content['children'] as $child) {
        $children[] = $child['content_id'];
        if ($recursive && !empty($child['children'])) {
          $this->getMoreChildrenOf($child['children'], $children);
        }
      }
    }
    return $children;
  }

  /**
   * @param array $items
   *
   * @return array
   */
  protected function getMoreChildrenOf(array $items, &$children) {
    foreach ($items as $item) {
      $children[] = $item['content_id'];
      if (!empty($item['children'])) {
        $this->getMoreChildrenOf($item['children'], $children);
      }
    }
  }

  /**
   * @return array
   */
  public function getAllContentIDs() {
    return $this->database->select('content_hierarchy', 'ch')
      ->fields('ch', ['content_id'])
      ->execute()
      ->fetchCol();
  }

  /**
   * @param string|null $langcode
   *
   * @return mixed|string
   */
  function correctLangCode($langcode) {
    if (is_null($langcode)) {
      $langcode = \Drupal::languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    }
    return $langcode;
  }

  /**
   * @param int $placement
   *
   * @return array
   */
  function placementToValues($placement) {
    $values = [
      'parent_id' => $placement,
      'root' => 0,
      'excluded' => 0,
      'weight' => 0
    ];
    switch ($placement) {
      case 0:
        $values['root'] = 1;
        $values['weight'] = -1000;
        break;
      case -1:
        $values['parent_id'] = 0;
        $values['excluded'] = 1;
        $values['weight'] = 1000;
        break;
    }
    return $values;
  }

}
