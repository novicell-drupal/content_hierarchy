<?php

namespace Drupal\content_hierarchy\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\content_hierarchy\Ajax\ToggleCommand;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\content_hierarchy\ContentHierarchyData;

/**
 * Class ContentOverviewController.
 */
class ContentOverviewController extends ControllerBase {

  /**
   * @var \Drupal\content_hierarchy\ContentHierarchyData
   */
  private $contentHierarchyData;

  /**
   * ContentOverviewController constructor.
   *
   * @param \Drupal\content_hierarchy\ContentHierarchyData $contentHierarchyData
   */
  public function __construct(ContentHierarchyData $contentHierarchyData) {
    $this->contentHierarchyData = $contentHierarchyData;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content_hierarchy.data')
    );
  }

  public function AjaxResponse($parent_id) {
    $list = [
      '#theme' => 'content_list',
      '#items' => $this->contentHierarchyData->getContentListItems($parent_id),
      '#attributes' => ['class' => ['content-hierarchy']],
    ];
    $response = new AjaxResponse();
    $response->addCommand(new ToggleCommand($parent_id, $list));
    return $response;
  }
}
