<?php

namespace Drupal\content_hierarchy\Plugin\Field\FieldWidget;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\entity_hierarchy\Plugin\Field\FieldWidget\EntityReferenceHierarchySelect;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Select widget.
 *
 * @FieldWidget(
 *   id = "content_hierarchy_select",
 *   label = @Translation("Content Hierarchy select"),
 *   description = @Translation("A select field sorted by hierarchy tree."),
 *   field_types = {
 *     "entity_reference_hierarchy"
 *   }
 * )
 */
class ContentHierarchySelect extends EntityReferenceHierarchySelect implements ContainerFactoryPluginInterface {

  /**
   * The account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   *    The current user.
   */
  private $currentUser;

  /**
   * The database.
   *
   * @var\Drupal\Core\Database\Connection
   *   The connection object.
   */
  private $database;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   *    The current route.
   */
  private $currentRouteMatch;

  /**
   * DdsEntityReferenceHierarchySelect constructor.
   *
   * @param                                                                   $plugin_id
   * @param                                                                   $plugin_definition
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   * @param array $settings
   * @param array $third_party_settings
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   * @param \Drupal\Core\Database\Connection $database
   * @param \Drupal\Core\Routing\CurrentRouteMatch $currentRouteMatch
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, AccountProxyInterface $currentUser, Connection $database, CurrentRouteMatch $currentRouteMatch) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->currentUser = $currentUser;
    $this->database = $database;
    $this->currentRouteMatch = $currentRouteMatch;
  }

  protected function generateHierarchyTree($level, $parent, $items, $field) {
    $options = [];
    $prefix = str_repeat('--', $level);
    foreach ($items as $item) {
      if($item->$field == $parent) {
        $options[$item->nid] = $prefix.$item->title;
        $options = $options + $this->generateHierarchyTree($level+1, $item->nid, $items, $field);
      }
    }
    return $options;
  }

  /**
   * Returns the array of options for the widget.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity for which to return options.
   *
   * @return array
   *   The array of options for the widget.
   */
  protected function getOptions(FieldableEntityInterface $entity) {
    if(!$entity->isNew()) {
      $langcode = $entity->language()->getId();
    }

    $options = [];

    if (!isset($this->options)) {
      $nids = $this->fieldDefinition
        ->getFieldStorageDefinition()
        ->getOptionsProvider($this->column, $entity)
        ->getSettableValues($this->currentUser);

      $nodes = [];
      if (!empty($nids)) {
        $config = \Drupal::config('content_hierarchy.hierarchy_settings');

        if(($key = array_search($config->get('node_403'), $nids)) !== false) {
          unset($nids[$key]);
        }
        if(($key = array_search($config->get('node_404'), $nids)) !== false) {
          unset($nids[$key]);
        }

        $query = $this->database->select('node_field_data', 'n');
        $query->leftJoin('node__field_parent', 'p', 'n.nid = p.entity_id');
        $query->condition('n.nid', $nids, 'IN');
        $query->fields('n', ['nid', 'title']);
        $query->fields('p', ['field_parent_target_id']);
        $query->orderBy('p.field_parent_weight');
        $query->orderBy('n.title');

        if(!empty($langcode)) {
          $query->condition('n.langcode', $langcode);
        }

        $nodes = $query->execute()->fetchAll();
      }

      $options = ['_none' => $this->t('No parent')];
      $options += $this->generateHierarchyTree(0, NULL, $nodes, 'field_parent_target_id');
    }

    return $options;

  }


  /**
   * Creates an instance of the plugin.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container to pull out services used in the plugin.
   * @param array                                                     $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string                                                    $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed                                                     $plugin_definition
   *   The plugin implementation definition.
   *
   * @return static
   *   Returns an instance of this plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('current_user'),
      $container->get('database'),
      $container->get('current_route_match')
    );

  }

}
