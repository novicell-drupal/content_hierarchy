<?php

namespace Drupal\content_hierarchy\Ajax;

use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Ajax\CommandWithAttachedAssetsInterface;
use Drupal\Core\Ajax\CommandWithAttachedAssetsTrait;

class ToggleCommand implements CommandInterface, CommandWithAttachedAssetsInterface {

  use CommandWithAttachedAssetsTrait;

  protected $id;

  protected $content;

  public function __construct($id, $content) {
    $this->id = $id;
    $this->content = $content;
  }

  public function render() {
    return [
      'command' => 'toggleCommand',
      'id' => $this->id,
      'data' => $this->getRenderedContent(),
    ];
  }
}
