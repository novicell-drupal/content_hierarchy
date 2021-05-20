<?php

namespace Drupal\content_hierarchy\Form;

use Drupal\content_hierarchy\ContentHierarchy;
use Drupal\content_hierarchy\ContentHierarchyStorage;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * @var \Drupal\content_hierarchy\ContentHierarchyStorage
   */
  private $contentHierarchyStorage;

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
  protected $dataFormatter;

  /**
   * ContentOverviewController constructor.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   * @param \Drupal\content_hierarchy\ContentHierarchyStorage $contentHierarchyStorage
   * @param \Drupal\Core\Render\RendererInterface $renderer
   * @param \Drupal\Core\Datetime\DateFormatter $dataFormatter
   */
  public function __construct(LanguageManagerInterface $languageManager, ContentHierarchyStorage $contentHierarchyStorage, RendererInterface $renderer, DateFormatter $dataFormatter, EntityTypeManagerInterface $entityTypeManager) {
    $this->languageManager = $languageManager;
    $this->contentHierarchyStorage = $contentHierarchyStorage;
    $this->renderer = $renderer;
    $this->dataFormatter = $dataFormatter;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   *
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('language_manager'),
      $container->get('content_hierarchy.storage'),
      $container->get('renderer'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager')
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
    $form['filter'] = [
      '#weight' => 0,
    ];
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $request = \Drupal::request();
    if ($request->query->has('langcode')) {
      $langcode = $request->query->get('langcode');
    }
    $languages = $this->getLanguageOptions();

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
    $form['#cache'] = [
      'tags' => $this->contentHierarchyStorage->getListCacheTags($langcode)
    ];

    $form['content_title'] = [
      '#prefix' => '<h3>',
      '#markup' => $this->t('Content hierarchy for @language', ['@language' => $languages[$langcode]]),
      '#suffix' => '</h3>'
    ];

    $update_tree_access = TRUE;
    $empty = $this->t('No content available.');
    $form['content'] = [
      '#type' => 'table',
      '#empty' => $empty,
      '#header' => [
        'content' => $this->t('Name'),
        'type' => $this->t('Type'),
        'status' => $this->t('Status'),
        'created' => $this->t('Created'),
        'changed' => $this->t('Changed'),
        'operations' => $this->t('Operations'),
        'weight' => $update_tree_access ? $this->t('Weight') : NULL,
      ],
      '#attributes' => [
        'id' => 'taxonomy',
      ],
    ];

    $delta = 0;
    $weight = 0;
    $parent_fields = FALSE;
    $items = $this->contentHierarchyStorage->getListWithDepth($langcode, FALSE);
    foreach ($items as $key => $item) {
      $form['content'][$key] = [
        'content' => [],
        'type' => [],
        'status' => [],
        'created' => [],
        'changed' => [],
        'operations' => [],
        'weight' => $update_tree_access ? [] : NULL,
      ];

      $form['content'][$key]['#content'] = $item;
      $indentation = [];
      if (!is_null($item->getDepth()) && $item->getDepth() > 0) {
        $indentation = [
          '#theme' => 'indentation',
          '#size' => $item->getDepth(),
        ];
      }
      $form['content'][$key]['content'] = [
        '#prefix' => !empty($indentation) ? $this->renderer->render($indentation) : '',
        '#type' => 'link',
        '#title' => $item->getTitle(),
        '#url' => $item->getUrl(),
      ];
      $form['content'][$key]['#attributes']['class'] = [];

      if ($update_tree_access) {
        $parent_fields = TRUE;
        $form['content'][$key]['content']['id'] = [
          '#type' => 'hidden',
          '#value' => $item->id(),
          '#attributes' => [
            'class' => ['content-id'],
          ],
        ];
        $form['content'][$key]['content']['parent'] = [
          '#type' => 'hidden',
          // Yes, default_value on a hidden. It needs to be changeable by the
          // javascript.
          '#default_value' => $item->getParentId(),
          '#attributes' => [
            'class' => ['content-parent'],
          ],
        ];
        $form['content'][$key]['content']['depth'] = [
          '#type' => 'hidden',
          // Same as above, the depth is modified by javascript, so it's a
          // default_value.
          '#default_value' => $item->getDepth(),
          '#attributes' => [
            'class' => ['content-depth'],
          ],
        ];

        $form['content'][$key]['weight'] = [
          '#type' => 'weight',
          '#delta' => $delta,
          '#title' => $this->t('Weight for added content'),
          '#title_display' => 'invisible',
          '#default_value' => $weight++,
          '#attributes' => ['class' => ['content-weight']],
        ];

        $form['content'][$key]['type'] = [
          '#type' => 'markup',
          '#markup' => $this->getTypeLabel($item)
        ];

        if (!is_null($item->getStatus())) {
          $form['content'][$key]['status'] = [
            '#type' => 'markup',
            '#markup' => $this->t($item->getStatus()),
          ];
        }

        if (!is_null($item->getCreated())) {
          $form['content'][$key]['created'] = [
            '#type' => 'markup',
            '#markup' => $this->dataFormatter->format($item->getCreated(), 'short')
          ];
        }

        if (!is_null($item->getChanged())) {
          $form['content'][$key]['changed'] = [
            '#type' => 'markup',
            '#markup' => $this->dataFormatter->format($item->getChanged(), 'short')
          ];
        }

        $form['content'][$key]['operations'] = [
          '#type' => 'operations',
          '#links' => $item->getOperations(),
        ];
      }

      if ($parent_fields) {
        $form['content'][$key]['#attributes']['class'][] = 'draggable';
      }
    }

    $this->renderer->addCacheableDependency($form['content'], $update_tree_access);
    if ($update_tree_access) {
      if ($parent_fields) {
        $form['content']['#tabledrag'][] = [
          'action' => 'match',
          'relationship' => 'parent',
          'group' => 'content-parent',
          'subgroup' => 'content-parent',
          'source' => 'content-id',
          'hidden' => FALSE,
        ];
        $form['content']['#tabledrag'][] = [
          'action' => 'depth',
          'relationship' => 'group',
          'group' => 'content-depth',
          'hidden' => FALSE,
        ];
        //$form['content']['#attached']['library'][] = 'taxonomy/drupal.taxonomy';
      }
      $form['content']['#tabledrag'][] = [
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => 'content-weight',
      ];
    }

    if ($update_tree_access) {
      $form['actions'] = ['#type' => 'actions', '#tree' => FALSE];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
        '#button_type' => 'primary',
      ];
    }

    return $form;
  }

  /**
   * @inheritDoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $langcode = $form_state->cleanValues()->getValues()['tree_langcode'];
    $items = $form_state->getValue('content');
    // Sort term order based on weight.
    uasort($items, ['Drupal\Component\Utility\SortArray', 'sortByWeightElement']);

    $changed_content = [];
    $weight = 0;

    // Renumber the current page weights and assign any new parents.
    $level_weights = [];
    foreach ($items as $id => $values) {
      if (isset($form['content'][$id]['#content'])) {
        /** @var ContentHierarchy $content */
        $content = $form['content'][$id]['#content'];
        // Give terms at the root level a weight in sequence with terms on previous pages.
        if ($values['content']['parent'] == 0 && $content->getWeight() != $weight) {
          $content->setWeight($weight);
          $changed_content[$content->id()] = $content;
        }
        // Terms not at the root level can safely start from 0 because they're all on this page.
        elseif ($values['content']['parent'] > 0) {
          $level_weights[$values['content']['parent']] = isset($level_weights[$values['content']['parent']]) ? $level_weights[$values['content']['parent']] + 1 : 0;
          if ($level_weights[$values['content']['parent']] != $content->getWeight()) {
            $content->setWeight($level_weights[$values['content']['parent']]);
            $changed_content[$content->id()] = $content;
          }
        }
        // Update any changed parents.
        if ($values['content']['parent'] != $content->getParentId()) {
          $content->setParentId($values['content']['parent']);
          $changed_content[$content->id()] = $content;
        }
        $weight++;
      }
    }
    $this->contentHierarchyStorage->saveMultiple($changed_content);

    $form_state->setRedirect('content_hierarchy.content_overview', [], ['query' => ['langcode' => $langcode]]);
  }

  /**
   * @inheritDoc
   */
  public function submitFilter(array &$form, FormStateInterface $form_state) {
    $langcode = $form_state->cleanValues()->getValues()['langcode'];
    $form_state->setRedirect('content_hierarchy.content_overview', [], ['query' => ['langcode' => $langcode]]);
  }

  /**
   * Returns the default options for the language configuration form element.
   *
   * @return array
   *   An array containing the default options.
   */
  protected function getLanguageOptions() {
    $language_options = [];

    $languages = $this->languageManager->getLanguages();
    foreach ($languages as $langcode => $language) {
      $language_options[$langcode] = $language->isLocked() ? t('- @name -', ['@name' => $language->getName()]) : $language->getName();
    }

    return $language_options;
  }

  /**
   * @param \Drupal\content_hierarchy\ContentHierarchy $content
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getTypeLabel(ContentHierarchy $content) {
    if ($content->getSource() == 'entity') {
      return $this->entityTypeManager
        ->getStorage($content->getEntityType() . '_type')
        ->load($content->getEntityBundle())
        ->label();
    } else {
      return $this->t($content->getType());
    }
  }
}
