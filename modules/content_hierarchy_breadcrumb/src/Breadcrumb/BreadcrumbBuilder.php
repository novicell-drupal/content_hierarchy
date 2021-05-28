<?php

namespace Drupal\content_hierarchy_breadcrumb\Breadcrumb;

use Drupal\content_hierarchy\ContentHierarchyStorage;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\Routing\Route;

class BreadcrumbBuilder implements BreadcrumbBuilderInterface {

  /**
   * The admin context service.
   *
   * @var \Drupal\Core\Routing\AdminContext
   */
  protected $adminContext;

  /**
   * Content Hierarchy storage service
   *
   * @var \Drupal\content_hierarchy\ContentHierarchyStorage
   */
  protected $contentHierarchyStorage;

  /**
   * HierarchyBasedBreadcrumbBuilder constructor.
   *
   * @param AdminContext $admin_context
   *   The admin context service.
   * @param ContentHierarchyStorage $contentHierarchyStorage
   *   Content Hierarchy storage service.
   */
  public function __construct(
    AdminContext $admin_context,
    ContentHierarchyStorage $contentHierarchyStorage
  ) {
    $this->adminContext = $admin_context;
    $this->contentHierarchyStorage = $contentHierarchyStorage;
  }

  /**
   * Whether this breadcrumb builder should be used to build the breadcrumb.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return bool
   *   TRUE if this builder should be used or FALSE to let other builders
   *   decide.
   */
  public function applies(\Drupal\Core\Routing\RouteMatchInterface $route_match) {
    if ($this->adminContext->isAdminRoute($route_match->getRouteObject())) {
      return FALSE;
    }

    $route_entity = $this->getEntityFromRouteMatch($route_match);
    if (!$route_entity || !$this->contentHierarchyStorage->isEntityInHierarchy($route_entity)) {
      return FALSE;
    }

    return TRUE;
  }

  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);
    /** @var \Drupal\Core\Entity\ContentEntityInterface $route_entity */
    $route_entity = $this->getEntityFromRouteMatch($route_match);
    if ($route_entity && $this->contentHierarchyStorage->isEntityInHierarchy($route_entity)) {

      $content = $this->contentHierarchyStorage->loadFromEntity($route_entity);
      $ancestors = $this->contentHierarchyStorage->findAncestors($content);
      $ancestors[$content->id()] = $content;
      $breadcrumb->addCacheTags(['content_hierarchy_placement:' . $content->id() . ':' . $content->getLangcode()]);

      $links = [];
      foreach ($ancestors as $content_ancestor) {
        if ($content_ancestor->isExcluded()) {
          // Is excluded from hierarchy
          continue;
        }

        /** @var EntityInterface $entity */
        $entity = $content_ancestor->getEntity();
        $breadcrumb->addCacheableDependency($entity);

        // Show just the label for the entity from the route.
        if ($entity->id() == $route_entity->id()) {
          $links[] = Link::createFromRoute($entity->label(), '<none>');
        }
        else {
          $links[] = $entity->toLink();
        }
      }

      /*if (count($links) > 2) {
        $links = array_slice($links, -2);
        array_unshift($links, Link::createFromRoute('...', '<none>'));
      }*/

      $breadcrumb->setLinks($links);
    }
    return $breadcrumb;
  }

  /**
   * Return the entity type id from a route object.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route object.
   *
   * @return string|null
   *   The entity type id, null if it doesn't exist.
   */
  protected function getEntityTypeFromRoute(Route $route) {
    if (!empty($route->getOptions()['parameters'])) {
      foreach ($route->getOptions()['parameters'] as $option) {
        if (isset($option['type']) && strpos($option['type'], 'entity:') === 0) {
          return substr($option['type'], strlen('entity:'));
        }
      }
    }

    return NULL;
  }

  /**
   * Returns an entity parameter from a route match object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity, or null if it's not an entity route.
   */
  protected function getEntityFromRouteMatch(RouteMatchInterface $route_match) {
    $route = $route_match->getRouteObject();
    if (!$route) {
      return NULL;
    }

    $entity_type_id = $this->getEntityTypeFromRoute($route);
    if ($entity_type_id) {
      return $route_match->getParameter($entity_type_id);
    }

    return NULL;
  }
}
