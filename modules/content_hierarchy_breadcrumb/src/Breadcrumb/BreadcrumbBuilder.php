<?php

namespace Drupal\content_hierarchy_breadcrumb\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\content_hierarchy\ContentHierarchyUtils;
use Drupal\entity_hierarchy_breadcrumb\HierarchyBasedBreadcrumbBuilder;

class BreadcrumbBuilder extends HierarchyBasedBreadcrumbBuilder {

  use StringTranslationTrait;

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
    if (!$route_entity || !$route_entity instanceof ContentEntityInterface || !$this->getHierarchyFieldFromEntity($route_entity)) {
      return FALSE;
    }

    return TRUE;
  }

  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    /** @var \Drupal\Core\Entity\ContentEntityInterface $route_entity */
    $route_entity = $this->getEntityFromRouteMatch($route_match);
    $breadcrumb->addCacheableDependency($route_match->getRouteObject());
    $frontpage = ContentHierarchyUtils::getFrontpageEntity();

    $entity_type = $route_entity->getEntityTypeId();
    $storage = $this->storageFactory->get($this->getHierarchyFieldFromEntity($route_entity), $entity_type);
    $ancestors = $storage->findAncestors($this->nodeKeyFactory->fromEntity($route_entity));
    // Pass in the breadcrumb object for caching.
    $ancestor_entities = $this->mapper->loadAndAccessCheckEntitysForTreeNodes($entity_type, $ancestors, $breadcrumb);

    $links = [];
    foreach ($ancestor_entities as $ancestor_entity) {
      if (!$ancestor_entities->contains($ancestor_entity)) {
        // Doesn't exist or is access hidden.
        continue;
      }

      $entity = $ancestor_entities->offsetGet($ancestor_entity);

      // We set the frontpage link later. So if nodes are children of the
      // frontpage it would show up twice
      if ((!is_null($frontpage) && $frontpage instanceof ContentEntityInterface) && $entity->id() == $frontpage->id()) {
        continue;
      }

      // Show just the label for the entity from the route.
      if ($entity->id() == $route_entity->id()) {
        $links[] = Link::createFromRoute($entity->label(), '<none>');
      }
      else {
        $links[] = $entity->toLink();
      }
    }

    if (count($links) > 2) {
      $links = array_slice($links, -2);
      array_unshift($links, Link::createFromRoute('...', '<none>'));
    }

    array_unshift($links, Link::createFromRoute($this->t('Frontpage'), '<front>'));

    $breadcrumb->setLinks($links);
    return $breadcrumb;
  }

}
