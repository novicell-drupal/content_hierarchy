<?php

namespace Drupal\content_hierarchy;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class ContentHierarchyData.
 *
 * @package Drupal\content_hierarchy
 */
class ContentHierarchyData {

  use StringTranslationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $database;

  /**
   * The current request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request|null
   */
  private $request;

  /**
   * The config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $config;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  private $languageManager;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * ContentOverviewController constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   */
  public function __construct(Connection $database, RequestStack $requestStack, ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entityTypeManager, LanguageManagerInterface $languageManager) {
    $this->database = $database;
    $this->request = $requestStack->getCurrentRequest();
    $this->config = $configFactory->get('content_hierarchy.hierarchy_settings');
    $this->languageManager = $languageManager;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Get the hierarchical content data.
   *
   * @param int $parent_id
   *   The parent id from where to get he children.
   *
   * @return array
   *   The raw data from the database.
   */
  protected function getContentHierarchicalData(int $parent_id): array {
    // Check if the field_parent table is created on nodes yet.
    if (!$this->database->schema()->tableExists('node__field_parent')) {
      \Drupal::logger('content_hierarchy')->notice('Table node__field_parent was not found, while trying to create the content hierarchy.');
      \Drupal::messenger()->addWarning('You need to add an Entity hierarchy field, "field_parent" to the nodes which should use content hierarchy. ');
      return [];
    }

    $subquery = $this->database->select('node__field_parent', 'x');
    $subquery->fields('x', ['field_parent_target_id', 'entity_id']);

    $query = $this->database->select('node_field_data', 'n');
    $query->leftJoin('node__field_parent', 'p', 'n.nid = p.entity_id');
    $query->leftJoin($subquery, 'p2', 'p2.field_parent_target_id = n.nid');

    // If a parent id is provided only get the node children.
    if ($parent_id > 0) {
      $query->condition('p.field_parent_target_id', $parent_id);
    }
    else {
      $query->isNull('p.field_parent_target_id');
    }

    // If any nodes is defined as ignored – ignore them.
    if (!empty($this->config->get('ignored_nodes'))) {
      $ignored_nodes = array_keys($this->config->get('ignored_nodes'));
      $query->condition('n.type', $ignored_nodes, 'NOT IN');
    }

    $query->orderBy('p.field_parent_weight');
    $query->orderBy('n.nid');
    $query->fields(
      'n', [
        'nid',
        'title',
        'type',
        'uid',
        'created',
        'changed',
        'status',
      ]
    );
    $query->fields('p2', ['entity_id']);
    $query->addTag('content_hierarchy_content_list');

    // Filter the nodes by language.
    $langcode = $this->getFilterParameters('langcode');
    if ($langcode !== NULL) {
      $query->condition('n.langcode', $this->convertLangcode($langcode));
    }

    return $query->execute()->fetchAllAssoc('nid');
  }

  /**
   * Get the content list items.
   *
   * @param int $parent_id
   *   The parent id.
   * @param string $sign
   *   The open/close sign.
   *
   * @return array
   *   Array og content hierarchical items.
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function getContentListItems(int $parent_id, string $sign = '( + )') {

    $result = $this->getContentHierarchicalData($parent_id);

    $nids = [];
    foreach ($result as $page) {
      $nids[] = $page->nid;
    }

    $nodes = Node::loadMultiple($nids);

    $items = [];
    /** @var \Drupal\Core\Entity\EntityListBuilderInterface $list_builder */
    $list_builder = $this->entityTypeManager->getListBuilder('node');

    foreach ($result as $page) {
      $node = $nodes[$page->nid];
      $nid = $page->nid;
      $expand = NULL;

      if ($page->entity_id > 0) {
        $url = Url::fromRoute('content_hierarchy.ajax', ['parent_id' => $nid]);
        $expand = Link::fromTextAndUrl($sign, $url)->toRenderable();
        $expand['#attributes']['class'][] = 'use-ajax';
      }

      $operations = $list_builder->getOperations($node);
      if (isset($operations['clone']) && $node->bundle() == 'special_page') {
        unset($operations['clone']);
      }

      $actions = [
        '#type' => 'dropbutton',
        '#links' => $operations,
      ];

      $items[] = [
        'title' => Link::fromTextAndUrl($page->title, Url::fromRoute('entity.node.canonical', ['node' => $nid]))->toRenderable(),
        'content_type' => ContentHierarchyUtils::getNodeType($page->type),
        'author' => isset($page->uid) ? ContentHierarchyUtils::getAuthorLink($page->uid) : NULL,
        'status' => ((boolean) $page->status) ? $this->t('Published') : $this->t('Unpublished'),
        'created' => $page->created,
        'changed' => $page->changed,
        'actions' => $actions,
        'id' => $nid,
        'expand' => $expand,
        'children' => [],
      ];
    }

    // Ensure that this query can easily be altered by other modules.
    /** @var \Drupal\Core\Extension\ModuleHandlerInterface $module_handler */
    \Drupal::service('module_handler')->alter(
      'content_hierarchy_content_list',
      $items
    );

    return $items;
  }

  /**
   * Get filters from url.
   *
   * @param string $name
   *   The name of parameter to get.
   *
   * @return mixed|null
   *   The parameter value if exists otherwise return NULL.
   */
  public function getFilterParameters($name) {
    $parameter = $this->request->get($name);
    if (!empty($parameter)) {
      return $parameter;
    }

    return NULL;
  }

  /**
   * Returns the converted language code.
   *
   * @param string $langcode
   *   The entity type.
   *
   * @return string
   *   The language code.
   */
  private function convertLangcode($langcode): string {
    $language_interface = $this->languageManager->getCurrentLanguage();
    switch ($langcode) {
      case LanguageInterface::LANGCODE_SITE_DEFAULT:
        $langcode = $this->languageManager->getDefaultLanguage()->getId();
        break;

      case 'current_interface':
        $langcode = $language_interface->getId();
        break;

      case 'authors_default':
        $user = \Drupal::currentUser();
        $language_code = $user->getPreferredLangcode();
        if (!empty($language_code)) {
          $langcode = $language_code;
        }
        else {
          $langcode = $language_interface->getId();
        }
        break;
    }
    if ($langcode) {
      return $langcode;
    }

    // If we still do not have a default value, just return the value stored in
    // the configuration; it has to be an actual language code.
    return $language_interface->getDefaultLangcode();
  }

}
