<?php

namespace Drupal\content_hierarchy\Form;

use Drupal\content_hierarchy\ContentHierarchy;
use Drupal\content_hierarchy\ContentHierarchyData;
use Drupal\content_hierarchy\ContentHierarchyStorage;
use Drupal\content_hierarchy\ContentHierarchyWidgets;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class ContentHierarchyOverviewBase extends FormBase {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * @var \Drupal\content_hierarchy\ContentHierarchyStorage
   */
  protected $contentHierarchyStorage;

  /**
   * @var \Drupal\content_hierarchy\ContentHierarchyData
   */
  protected $contentHierarchyData;

  /**
   * @var \Drupal\content_hierarchy\ContentHierarchyWidgets
   */
  protected $contentHierarchyWidgets;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The pager manager service.
   *
   * @var PagerManagerInterface
   */
  private $pagerManager;

  /**
   * ContentOverviewController constructor.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   * @param \Drupal\content_hierarchy\ContentHierarchyStorage $contentHierarchyStorage
   * @param \Drupal\Core\Render\RendererInterface $renderer
   * @param \Drupal\Core\Datetime\DateFormatter $dateFormatter
   */
  public function __construct(LanguageManagerInterface $languageManager, ContentHierarchyStorage $contentHierarchyStorage, ContentHierarchyData $contentHierarchyData, ContentHierarchyWidgets $contentHierarchyWidgets, RendererInterface $renderer, DateFormatter $dateFormatter, EntityTypeManagerInterface $entityTypeManager, PagerManagerInterface $pagerManager) {
    $this->languageManager = $languageManager;
    $this->contentHierarchyStorage = $contentHierarchyStorage;
    $this->contentHierarchyData = $contentHierarchyData;
    $this->contentHierarchyWidgets = $contentHierarchyWidgets;
    $this->renderer = $renderer;
    $this->dateFormatter = $dateFormatter;
    $this->entityTypeManager = $entityTypeManager;
    $this->pagerManager = $pagerManager;
  }

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('language_manager'),
      $container->get('content_hierarchy.storage'),
      $container->get('content_hierarchy.data'),
      $container->get('content_hierarchy.widgets'),
      $container->get('renderer'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('pager.manager'),
    );
  }

  /**
   * @return string
   */
  abstract public function getRouteName();

  /**
   * @inheritDoc
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('content_hierarchy.hierarchy_settings');
    $langcode = $this->getLangCode();
    $update_tree_access = TRUE;

    if ($config->get('multilingual') ?? TRUE) {
      $languages = $this->getLanguageOptions();

      $form['filter'] = [
        '#weight' => 0,
      ];
      $form['filter']['langcode'] = [
        '#type' => 'select',
        '#title' => $this->t('Language'),
        '#options' => $languages,
        '#default_value' => $langcode,
      ];

      $form['filter']['actions'] = [
        '#type' => 'actions',
        '#weight' => 0,
      ];

      $form['filter']['actions']['filter_submit'] = [
        '#type' => 'submit',
        '#submit' => ['::submitFilter'],
        '#value' => $this->t('Filter'),
      ];

      $form['tree_langcode'] = [
        '#type' => 'hidden',
        '#default_value' => $langcode,
      ];

      $form['content_title'] = [
        '#prefix' => '<h3>',
        '#markup' => $this->t('Content hierarchy for @language', ['@language' => $languages[$langcode]]),
        '#suffix' => '</h3>'
      ];
    } else {
      $form['content_title'] = [
        '#prefix' => '<h3>',
        '#markup' => $this->t('Content Hierarchy overview'),
        '#suffix' => '</h3>'
      ];
    }

    $form['#cache'] = [
      'tags' => $this->contentHierarchyStorage->getListCacheTags($langcode)
    ];

    return $form;
  }

  public function submitFilter(array &$form, FormStateInterface $form_state) {
    $langcode = $form_state->cleanValues()->getValues()['langcode'];
    $form_state->setRedirect($this->getRouteName(), [], ['query' => ['langcode' => $langcode]]);
  }

  /**
   * Returns the default options for the language configuration form element.
   *
   * @return array
   *   An array containing the default options.
   */
  protected function getLanguageOptions() {
    $language_options = [];

    $languages = $this->getLanguageManager()->getLanguages();
    foreach ($languages as $langcode => $language) {
      $language_options[$langcode] = $language->isLocked() ? t('- @name -', ['@name' => $language->getName()]) : $language->getName();
    }

    return $language_options;
  }

  /**
   * @param \Drupal\content_hierarchy\ContentHierarchy|array $content
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getTypeLabel($content) {
    if (is_array($content)) {
      if ($content['source'] == 'entity') {
        return $this->getEntityTypeLabel($content['entity_type'], $content['entity_bundle']);
      } else {
        return $this->t($content['type']);
      }
    }
    else {
      if ($content->getSource() == 'entity') {
        return $this->getEntityTypeLabel($content->getEntityType(), $content->getEntityBundle());
      }
      else {
        return $this->t($content->getType());
      }
    }
  }

  protected function getEntityTypeLabel($entity_type, $entity_bundle) {
    static $types = [];
    if (empty($types)) {
      $types = $this->contentHierarchyWidgets->getSupportedEntityTypes();
    }
    return $types[$entity_type]['bundles'][$entity_bundle]['label'];
  }

  protected function getLangCode() {
    $config = $this->config('content_hierarchy.hierarchy_settings');
    if ($config->get('multilingual') ?? TRUE) {
      $langcode = $this->getLanguageManager()
        ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
        ->getId();
      $request = \Drupal::request();
      if ($request->query->has('langcode')) {
        $langcode = $request->query->get('langcode');
      }
    } else {
      $langcode = $this->getLanguageManager()->getDefaultLanguage()->getId();
    }
    return $langcode;
  }

  /**
   * @return DateFormatter
   */
  protected function getDateFormatter() {
    if (!$this->dateFormatter) {
      $this->dateFormatter = \Drupal::service('date.formatter');
    }
    return $this->dateFormatter;
  }

  /**
   * @return RendererInterface
   */
  protected function getRenderer() {
    if (!$this->renderer) {
      $this->renderer = \Drupal::service('renderer');
    }
    return $this->renderer;
  }

  /**
   * Gets the language manager.
   *
   * @return LanguageManagerInterface
   *   The language manager.
   */
  protected function getLanguageManager() {
    if (!$this->languageManager) {
      $this->languageManager = \Drupal::service('language_manager');
    }
    return $this->languageManager;
  }

  /**
   * Gets the pager manager.
   *
   * @return PagerManagerInterface
   *   The pager manager.
   */
  protected function getPagerManager() {
    if (!$this->pagerManager) {
      $this->pagerManager = \Drupal::service('pager.manager');
    }
    return $this->pagerManager;
  }
}
