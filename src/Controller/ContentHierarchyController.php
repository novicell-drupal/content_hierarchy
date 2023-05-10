<?php
namespace Drupal\content_hierarchy\Controller;

use Drupal\content_hierarchy\ContentHierarchyStorage;
use Drupal\content_hierarchy\ContentHierarchyWidgets;
use Drupal\content_hierarchy\Plugin\Field\FieldWidget\ContentHierarchySelect;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\OpenDialogCommand;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

class ContentHierarchyController extends ControllerBase {

  /**
   * Content Hierarchy widgets service
   *
   * @var \Drupal\content_hierarchy\ContentHierarchyWidgets
   */
  protected $widgets;

  /**
   * Content Hierarchy storage service
   *
   * @var \Drupal\content_hierarchy\ContentHierarchyStorage
   */
  protected $storage;

  /**
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  public function __construct(ContentHierarchyWidgets $widgets, ContentHierarchyStorage $storage, LanguageManagerInterface $languageManager, RendererInterface $renderer) {
    $this->widgets = $widgets;
    $this->storage = $storage;
    $this->languageManager = $languageManager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content_hierarchy.widgets'),
      $container->get('content_hierarchy.storage'),
      $container->get('language_manager'),
      $container->get('renderer')
    );
  }

  /**
   * @param string $langcode
   * @param string $current_lang
   * @param string $content_id
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function contentHierarchySelect($langcode, $current_lang, $content_id) {
    if ($langcode == 'undefined') {
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
    }
    $build = [
      '#theme' => 'content_hierarchy_options',
      '#langcode' => $langcode
    ];
    if ($langcode == $current_lang && $content_id > 0) {
      $build['#content_id'] = intval($content_id);
    }
    return new HtmlResponse($this->renderer->renderRoot($build));
  }

  /**
   * @param string $langcode
   * @param string $content_id
   *
   * @return AjaxResponse
   */
  public function contentHierarchyModalUpdate($langcode, $content_id) {
    if ($langcode == 'undefined') {
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
    }
    if ($content_id == 0) {
      $content_id = NULL;
      $id = -1;
    } else {
      $content = $this->storage->load($content_id, $langcode);
      if (!empty($content)) {
        $id = $content->getPlacement();
      }
    }
    $build = $this->widgets->buildModalWidget($langcode, $content_id, intval($id));

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('.content-hierarchy-modal__widget', $this->renderer->renderRoot($build)));
    $response->addCommand(new InvokeCommand('.content-hierarchy-modal input[type="hidden"]', 'val', [$id]));
    return $response;
  }

  /**
   * @param int $id
   * @param string $langcode
   * @param int $content_id
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function contentHierarchyModalOpen($id, $langcode, $content_id) {
    /** @var ContentHierarchyStorage $storage */
    $storage = \Drupal::service('content_hierarchy.storage');
    $content = $storage->load($id);
    $build = $this->widgets->buildModalItem($content, $langcode, $content_id, TRUE);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#hierarchy-' . $id, $this->renderer->renderRoot($build)));
    return $response;
  }

  /**
   * @param int $id
   * @param string $langcode
   * @param int $content_id
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function contentHierarchyModalClose($id, $langcode, $content_id) {
    /** @var ContentHierarchyStorage $storage */
    $storage = \Drupal::service('content_hierarchy.storage');
    $content = $storage->load($id);
    $build = $this->widgets->buildModalItem($content, $langcode, $content_id, FALSE);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#hierarchy-' . $id, $this->renderer->renderRoot($build)));
    return $response;
  }

  /**
   * @param int $id
   * @param string $langcode
   * @param int $content_id
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function contentHierarchyModalSelect($id, $langcode, $content_id) {
    if ($content_id == 0) {
      $content_id = NULL;
    }
    $build = $this->widgets->buildModalWidget($langcode, $content_id, intval($id));

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('.content-hierarchy-modal__widget', $this->renderer->renderRoot($build)));
    $response->addCommand(new InvokeCommand('.content-hierarchy-modal input[type="hidden"]', 'val', [$id]));
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

}
