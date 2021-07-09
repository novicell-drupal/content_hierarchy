<?php

namespace Drupal\content_hierarchy_breadcrumb\Breadcrumb;

use Drupal\content_hierarchy\ContentHierarchyStorage;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\Routing\Route;
use Drupal\Core\Entity\EntityInterface;
use Drupal\content_hierarchy\ContentHierarchy;

/**
 * Add breadcrumb which used content hierarchy.
 *
 * @package Drupal\content_hierarchy_breadcrumb\Breadcrumb
 */
class BreadcrumbBuilder implements BreadcrumbBuilderInterface {

  /**
   * The admin context service.
   *
   * @var \Drupal\Core\Routing\AdminContext
   */
  protected $adminContext;

  /**
   * Content Hierarchy storage service.
   *
   * @var \Drupal\content_hierarchy\ContentHierarchyStorage
   */
  protected $contentHierarchyStorage;

  /**
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * HierarchyBasedBreadcrumbBuilder constructor.
   *
   * @param \Drupal\Core\Routing\AdminContext $admin_context
   *   The admin context service.
   * @param \Drupal\content_hierarchy\ContentHierarchyStorage $contentHierarchyStorage
   *   Content Hierarchy storage service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   Logger.
   */
  public function __construct(
    AdminContext $admin_context,
    ContentHierarchyStorage $contentHierarchyStorage,
    LoggerChannelFactoryInterface $loggerChannelFactory
  ) {
    $this->adminContext = $admin_context;
    $this->contentHierarchyStorage = $contentHierarchyStorage;
    $this->logger = $loggerChannelFactory->get('content_hierarchy_breadcrumb');
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
  public function applies(RouteMatchInterface $route_match): bool {
    if ($this->adminContext->isAdminRoute($route_match->getRouteObject())) {
      return FALSE;
    }

    $route_entity = $this->getEntityFromRouteMatch($route_match);
    if (!$route_entity || !$this->contentHierarchyStorage->isEntityInHierarchy($route_entity)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritDoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);

    /** @var \Drupal\Core\Entity\ContentEntityInterface $route_entity */
    $route_entity = $this->getEntityFromRouteMatch($route_match);
    $content_data = $this->getContentHierarchyData($route_entity);

    if (!is_null($content_data)) {
      $ancestors = $this->getAncestors($route_entity);
      $ancestors[$content_data->id()] = $content_data;
      $breadcrumb->addCacheTags(['content_hierarchy_placement:' . $content_data->id() . ':' . $content_data->getLangcode()]);

      $links = [];
      foreach ($ancestors as $content_ancestor) {
        if ($content_ancestor->isExcluded()) {
          // Is excluded from hierarchy.
          continue;
        }

        /** @var \Drupal\Core\Entity\EntityInterface $entity */
        $entity = $content_ancestor->getEntity();
        $breadcrumb->addCacheableDependency($entity);

        try {
          $this->buildLink($entity, $route_entity, $links);
        }
        catch (EntityMalformedException $e) {
          $this->logger->error($e->getMessage());
        }
      }

      $breadcrumb->setLinks($links);
    }
    return $breadcrumb;
  }

  /**
   * Building links for breadcrumb.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   * @param \Drupal\Core\Entity\ContentEntityInterface $route_entity
   *   Route entity.
   * @param array $links
   *   Links as reference.
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  protected function buildLink(EntityInterface $entity, ContentEntityInterface $route_entity, array &$links): void {
    // Show just the label for the entity from the route.
    if ($entity->id() === $route_entity->id()) {
      $links[] = Link::createFromRoute($entity->label(), '<none>');
    }
    else {
      $links[] = $entity->toLink();
    }
  }

  /**
   * Get stored content hierarchy object.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $route_entity
   *   Route entity.
   *
   * @return \Drupal\content_hierarchy\ContentHierarchy|null
   *   Get content hierarchy object or null if not found.
   */
  protected function getContentHierarchyData(ContentEntityInterface $route_entity): ?ContentHierarchy {
    if ($route_entity && $this->contentHierarchyStorage->isEntityInHierarchy($route_entity)) {
      return $this->contentHierarchyStorage->loadFromEntity($route_entity);
    }
    return NULL;
  }

  /**
   * Get all ancestors from entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $route_entity
   *   Route entity.
   *
   * @return array
   *   Ancestors.
   */
  protected function getAncestors(ContentEntityInterface $route_entity): array {
    $content = $this->getContentHierarchyData($route_entity);
    if (!$content instanceof ContentHierarchy) {
      return [];
    }
    return $this->contentHierarchyStorage->findAncestors($content);
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
  protected function getEntityTypeFromRoute(Route $route): ?string {
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
  protected function getEntityFromRouteMatch(RouteMatchInterface $route_match): ?EntityInterface {
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
