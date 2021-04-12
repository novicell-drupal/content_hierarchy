# Content Hierarchy #
This module provides the content editor with a way to organize her content and bring structure to the site
using an approach familiar to many people used to Umbraco.

## Hooks exposed by this module ##
**Altering the content list:**
```php
// Alter the content list query
HOOK_query_content_hierarchy_content_list_alter()

// Alter or replace the items returned by the content list
HOOK_content_hierarchy_content_list_alter($items)
```

## Submodules ##
This module provides the following submodules:

#### Content Hierarchy Path ####
This module creates a pathauto pattern, that will generate URL aliases for nodes, based on the nodes placement in
the content hierarchy.

#### Content Hierarchy Breadcrumb ####
This module generates a breadcrumb, based on the nodes placement in the content hierarchy, by extending the
functionality in the entity_hierarchy_breadcrumb module.
