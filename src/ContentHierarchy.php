<?php
namespace Drupal\content_hierarchy;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

class ContentHierarchy {

  /**
   * @var int|null
   */
  protected $content_id = NULL;

  /**
   * @var string
   */
  protected $source = '';

  /**
   * @var string
   */
  protected $type = '';

  /**
   * @var string|int|null
   */
  protected $entity_id = NULL;

  /**
   * @var string
   */
  protected $langcode = '';

  /**
   * @var int|null
   */
  protected $parent_id = NULL;

  /**
   * @var int
   */
  protected $weight = 0;

  /**
   * @var int|null
   */
  protected $depth = NULL;

  /**
   * @var bool
   */
  protected $root = FALSE;

  /**
   * @var bool
   */
  protected $excluded = TRUE;

  /**
   * @var string
   */
  protected $title = '';

  /**
   * @var string
   */
  protected $entity_type = NULL;

  /**
   * @var string
   */
  protected $entity_bundle = NULL;

  /**
   * @var string
   */
  protected $status = NULL;

  /**
   * @var int
   */
  protected $created = NULL;

  /**
   * @var int
   */
  protected $changed = NULL;

  /**
   * @var Url|null
   */
  protected $url;

  /**
   * @var array
   */
  protected $operations = [];

  /**
   * @var ContentHierarchy[]|null
   */
  protected $children = NULL;

  /**
   * @var EntityInterface
   */
  protected $entity = NULL;

  public function __construct(array $values) {
    $this->content_id = $values['content_id'] ?? NULL;
    $this->source = $values['source'] ?? '';
    $this->type = $values['type'] ?? '';
    $this->entity_id = $values['entity_id'] ?? NULL;

    $this->langcode = $values['langcode'] ?? 'und';
    $this->parent_id = $values['parent_id'] ?? 0;
    $this->weight = $values['weight'] ?? 0;
    $this->root = ($values['root'] ?? 0) == 1;
    $this->excluded = ($values['excluded'] ?? 1) == 1;

    $this->depth = $values['depth'] ?? NULL;

    $this->title = $values['title'] ?? '';
    $this->entity_type = $values['entity_type'] ?? NULL;
    $this->entity_bundle = $values['entity_bundle'] ?? NULL;
    $this->created = $values['created'] ?? NULL;
    $this->changed = $values['changed'] ?? NULL;
    $this->status = $values['status'] ?? NULL;
    $this->url = $values['url'] ?? NULL;
    $this->operations = $values['operations'] ?? [];

    if (isset($values['children'])) {
      $this->children = [];
      if (!empty($values['children'])) {
        foreach ($values['children'] as $key => $item) {
          $this->children[$key] = new ContentHierarchy($item);
        }
      }
    }
  }

  /**
   * @return int|null
   */
  public function id(): ?int {
    return $this->content_id;
  }

  /**
   * @return int|null
   */
  public function getContentId(): ?int {
    return $this->content_id;
  }

  /**
   * @return string
   */
  public function getSource(): string {
    return $this->source;
  }

  /**
   * @return string
   */
  public function getType(): string {
    return $this->type;
  }

  public function getEntity(): ?EntityInterface {
    if (is_null($this->entity)) {
      $this->entity = \Drupal::entityTypeManager()->getStorage($this->getType())->load($this->getEntityId());
      if ($this->entity instanceof ContentEntityInterface) {
        $this->entity = $this->entity->getTranslation($this->getLangcode());
      }
    }
    return $this->entity;
  }

  /**
   * @return int|string|null
   */
  public function getEntityId() {
    return $this->entity_id;
  }

  /**
   * @return string
   */
  public function getLangcode(): string {
    return $this->langcode;
  }

  /**
   * @param string $langcode
   */
  public function getTranslation(string $langcode): self {
    $this->langcode = $langcode;
    // TODO
    return $this;
  }

  /**
   * @return int|null
   */
  public function getParentId(): ?int {
    return $this->parent_id;
  }

  /**
   * @param int $parent_id
   */
  public function setParentId(int $parent_id): void {
    if (is_int($this->parent_id) && $this->parent_id > 0) {
      $this->parent_id = $parent_id;
    } else {
      $this->setPlacement($parent_id);
    }
  }

  /**
   * @return int
   */
  public function getPlacement(): int {
    if ($this->isRoot()) {
      return 0;
    } elseif ($this->isExcluded()) {
      return -1;
    } else {
      return $this->parent_id;
    }
  }

  /**
   * @param int $placement
   */
  public function setPlacement(int $placement): void {
    if ($placement === -1) {
      $this->parent_id = 0;
      $this->root = FALSE;
      $this->excluded = TRUE;
    } else {
      $this->parent_id = $placement;
      $this->excluded = FALSE;
      $this->root = ($placement === 0);
    }
  }

  /**
   * @return bool
   */
  public function isRoot(): bool {
    return $this->root;
  }

  /**
   * @param bool $root
   */
  public function setRoot(bool $root): void {
    $this->root = $root;
    if ($root) {
      $this->parent_id = 0;
      $this->excluded = FALSE;
    }
  }

  /**
   * @return bool
   */
  public function isExcluded(): bool {
    return $this->excluded;
  }

  /**
   * @param bool $excluded
   */
  public function setExcluded(bool $excluded): void {
    $this->excluded = $excluded;
    if ($excluded) {
      $this->parent_id = 0;
      $this->root = FALSE;
    }
  }

  /**
   * @return string
   */
  public function label(): string {
    return $this->title;
  }

  /**
   * @return string
   */
  public function getTitle(): string {
    return $this->title;
  }

  /**
   * @return \Drupal\Core\Url|null
   */
  public function getUrl() {
    return $this->url;
  }

  /**
   * @return int
   */
  public function getDepth() {
    if (is_null($this->depth)) {
      // TODO
    }
    return $this->depth;
  }

  /**
   * @return int
   */
  public function getWeight() {
    return $this->weight;
  }

  /**
   * @param int $weight
   */
  public function setWeight($weight): void {
    $this->weight = $weight;
  }

  /**
   * @return ContentHierarchy[]
   */
  public function getChildren(): array {
    if (is_null($this->children)) {
      // TODO
    }
    return $this->children;
  }

  /**
   * @return array
   */
  public function getOperations() {
    return $this->operations;
  }

  /**
   * @return string
   */
  public function getEntityType(): ?string {
    return $this->entity_type;
  }

  /**
   * @return string
   */
  public function getEntityBundle(): ?string {
    return $this->entity_bundle;
  }

  /**
   * @return int
   */
  public function getCreated(): ?int {
    return $this->created;
  }

  /**
   * @return int
   */
  public function getChanged(): ?int {
    return $this->changed;
  }

  /**
   * @return string
   */
  public function getStatus(): ?string {
    return $this->status;
  }

}
