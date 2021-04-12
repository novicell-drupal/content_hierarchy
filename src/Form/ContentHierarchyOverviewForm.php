<?php

namespace Drupal\content_hierarchy\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Template\Attribute;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\content_hierarchy\ContentHierarchyData;

/**
 * Class ContentOverviewController.
 */
class ContentHierarchyOverviewForm extends FormBase {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  private $languageManager;

  /**
   * @var \Drupal\content_hierarchy\ContentHierarchyData
   */
  private $contentHierarchyData;

  /**
   * ContentOverviewController constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   * @param \Drupal\content_hierarchy\ContentHierarchyData $contentHierarchyData
   */
  public function __construct(LanguageManagerInterface $languageManager, ContentHierarchyData $contentHierarchyData) {
    $this->languageManager = $languageManager;
    $this->contentHierarchyData = $contentHierarchyData;
  }

  /**
   *
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('language_manager'),
      $container->get('content_hierarchy.data')
    );
  }

  /**
   * @inheritDoc
   */
  public function getFormId() {
    return 'content_overview_form';
  }

  /**
   * Return overview page.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $items = $this->contentHierarchyData->getContentListItems(0, '( - )');
    /** @var \Drupal\Core\Template\Attribute $attribute */
    $attributeItems = new Attribute();
    $attributeItems->addClass('content-hierarchy');
    foreach ($items as $key => $item) {
      $items[$key]['children'] = [
        '#theme' => 'content_list',
        '#items' => $this->contentHierarchyData->getContentListItems($item['id']),
        '#attributes' => $attributeItems,
      ];
    }

    $attributeItemsOverviewParent = new Attribute();
    $attributeItemsOverviewParent->addClass('content-hierarchy');
    $attributeItemsOverviewParent->addClass('content-hierarchy-parent');

    $form['langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $this->getLanguageOptions(),
      '#default_value' => $this->contentHierarchyData->getFilterParameters('langcode'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 0,
    ];

    $form['actions']['filter_submit'] = [
      '#type' => 'submit',
      '#value' => $this > t('Filter'),
    ];

    $form['content_overview'] = [
      '#theme' => 'content_overview',
      '#title' => t('Content Hierarchy Overview'),
      '#list' => [
        '#theme' => 'content_list',
        '#items' => $items,
        '#attributes' => $attributeItemsOverviewParent,
      ],
      '#attached' => ['library' => ['core/drupal.ajax', 'content_hierarchy/ajax-commands']],
    ];

    return $form;
  }

  /**
   * @inheritDoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->cleanValues()->getValues();
    $form_state->setRedirect('content_hierarchy.content_overview', [], ['query' => $values]);
  }

  /**
   * Returns the default options for the language configuration form element.
   *
   * @return array
   *   An array containing the default options.
   */
  protected function getLanguageOptions() {
    $language_options = [
      LanguageInterface::LANGCODE_SITE_DEFAULT => t("Site's default language (@language)", ['@language' => $this->languageManager->getDefaultLanguage()->getName()]),
      'current_interface' => t('Interface text language selected for page'),
      'authors_default' => t("Author's preferred language"),
    ];

    $languages = $this->languageManager->getLanguages(LanguageInterface::STATE_ALL);
    foreach ($languages as $langcode => $language) {
      $language_options[$langcode] = $language->isLocked() ? t('- @name -', ['@name' => $language->getName()]) : $language->getName();
    }

    return $language_options;
  }

}
