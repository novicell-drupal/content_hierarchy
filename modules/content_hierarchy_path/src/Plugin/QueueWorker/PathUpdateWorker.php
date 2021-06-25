<?php
namespace Drupal\content_hierarchy_path\Plugin\QueueWorker;

use Drupal\content_hierarchy_path\ContentHierarchyPathUpdater;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Content Hierarchy path updater.
 *
 * @QueueWorker(
 *   id = "content_hierarchy_path_update",
 *   title = @Translation("Content Hierarchy path updater"),
 *   cron = {"time" = 10}
 * )
 */
class PathUpdateWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\content_hierarchy_path\ContentHierarchyPathUpdater
   */
  private $updater;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, ContentHierarchyPathUpdater $updater)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->updater = $updater;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('content_hierarchy_path.updater'));
  }

  public function processItem($data) {
    $this->updater->updatePath($data['content_id'], $data['langcode']);
  }

}
