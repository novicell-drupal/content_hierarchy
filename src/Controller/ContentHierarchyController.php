<?php
namespace Drupal\content_hierarchy\Controller;

use Drupal\content_hierarchy\ContentHierarchyStorage;
use Drupal\content_hierarchy\ContentHierarchyWidgets;
use Drupal\content_hierarchy\Plugin\Field\FieldWidget\ContentHierarchySelect;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

class ContentHierarchyController extends ControllerBase {

  /**
   * Content Hierarchy widgets service
   *
   * @var \Drupal\content_hierarchy\ContentHierarchyWidgets
   */
  protected $widgets;

  public function __construct(ContentHierarchyWidgets $widgets) {
    $this->widgets = $widgets;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content_hierarchy.widgets')
    );
  }

  /**
   * @param string $langcode
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function contentHierarchySelect($langcode, $current_lang, $placement) {
    if ($langcode == 'undefined') {
      /** @var \Drupal\Core\Language\LanguageManagerInterface $languageManager */
      $languageManager = \Drupal::service('language_manager');
      $langcode = $languageManager->getDefaultLanguage()->getId();
    }
    $build = [
      '#theme' => 'content_options',
      '#langcode' => $langcode
    ];
    if ($langcode == $current_lang) {
      $build['#placement'] = $placement;
    }
    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = \Drupal::service('renderer');
    return new HtmlResponse($renderer->renderRoot($build));
  }

}
