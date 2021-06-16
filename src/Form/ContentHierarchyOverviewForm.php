<?php

namespace Drupal\content_hierarchy\Form;

use Drupal\content_hierarchy\ContentHierarchy;
use Drupal\content_hierarchy\ContentHierarchyStorage;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
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
   * @var \Drupal\content_hierarchy\ContentHierarchyData
   */
  private $contentHierarchyData;

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
   * ContentOverviewController constructor.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   * @param \Drupal\content_hierarchy\ContentHierarchyStorage $contentHierarchyStorage
   * @param \Drupal\Core\Render\RendererInterface $renderer
   * @param \Drupal\Core\Datetime\DateFormatter $dateFormatter
   */
  public function __construct(LanguageManagerInterface $languageManager, ContentHierarchyStorage $contentHierarchyStorage, RendererInterface $renderer, DateFormatter $dateFormatter, EntityTypeManagerInterface $entityTypeManager) {
    $this->languageManager = $languageManager;
    $this->contentHierarchyStorage = $contentHierarchyStorage;
    $this->renderer = $renderer;
    $this->dateFormatter = $dateFormatter;
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
    $config = $this->config('content_hierarchy.hierarchy_settings');
    $langcode = $this->getLangCode();
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
      'tags' => $this->getContentHierarchyStorage()->getListCacheTags($langcode)
    ];

    $update_tree_access = TRUE;
    $empty = $this->t('No content available.');
    if ($config->get('overview_type') == 'foldable') {
      $this->buildFoldable($form, $form_state, $langcode, $update_tree_access, $empty);
    } else {
      $this->buildDraggable($form, $langcode, $update_tree_access, $empty);
    }

    return $form;
  }

  protected function buildFoldable(&$form, FormStateInterface $form_state, $langcode, $update_tree_access, $empty) {
    $maxDepth = 1;
    $open_items = $form_state->get('open_items');
    if (is_null($open_items)) {
      $open_items = ['0' => TRUE];
      $items = $this->getContentHierarchyData()->getLanguageListWithDepth($langcode, $open_items);
      foreach ($items as $key => $item) {
        if ($item['depth'] < $maxDepth) {
          $open_items[$item['content_id']] = TRUE;
        }
      }
      $form_state->set('open_items', $open_items);
    }
    $items = $this->getContentHierarchyData()->getLanguageListWithDepth($langcode, $open_items);

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
      ],
      '#prefix' => '<div id="content-hierarchy-overview">',
      '#suffix' => '</div>',
      '#attached' => ['library' => ['content_hierarchy/overview']],
    ];

    foreach ($items as $key => $item) {
      if ($item['root'] == 0 && empty($open_items[$item['parent_id']])) {
        continue;
      }

      $form['content'][$key] = [
        'content' => [],
        'type' => [],
        'status' => [],
        'created' => [],
        'changed' => [],
        'operations' => []
      ];

      $this->getContentHierarchyStorage()->populateContent($item);
      $form['content'][$key]['#content'] = $item;

      if (!is_null($item['depth']) && $item['depth'] > 0) {
        $form['content'][$key]['content'][] = [
          '#theme' => 'indentation',
          '#size' => $item['depth'],
        ];
      }

      if (!empty($item['children'])) {
        $form['content'][$key]['content'][] = [
          '#type' => 'submit',
          '#value' => empty($open_items[$item['content_id']]) ? '( + )' : '( - )',
          '#submit' => ['::toggleItem'],
          '#name' => 'item-' . $item['content_id'],
          '#attributes' => [
            'class' => [
              'toggle-item-button',
            ],
          ],
          '#ajax' => [
            'disable-refocus' => TRUE, // Or TRUE to prevent re-focusing on the triggering element.
            'callback' => '::overviewCallback',
            'wrapper' => 'content-hierarchy-overview',
            'progress' => [
              'type' => 'throbber',
            ],
          ],
        ];
      }

      $form['content'][$key]['content'][] = [
        '#type' => 'link',
        '#title' => $item['title'],
        '#url' => $item['url'],
      ];

      $form['content'][$key]['type'] = [
        '#type' => 'markup',
        '#markup' => $this->getTypeLabel($item)
      ];

      if (!is_null($item['status'])) {
        $form['content'][$key]['status'] = [
          '#type' => 'markup',
          '#markup' => $this->t($item['status']),
        ];
      }

      if (!is_null($item['created'])) {
        $form['content'][$key]['created'] = [
          '#type' => 'markup',
          '#markup' => $this->getDateFormatter()->format($item['created'], 'short')
        ];
      }

      if (!is_null($item['changed'])) {
        $form['content'][$key]['changed'] = [
          '#type' => 'markup',
          '#markup' => $this->getDateFormatter()->format($item['changed'], 'short')
        ];
      }

      if ($update_tree_access) {
        $form['content'][$key]['operations'] = [
          '#type' => 'operations',
          '#links' => $item['operations'],
        ];
      }
    }
  }

  public function toggleItem(array &$form, FormStateInterface $form_state) {
    $item_id = intval(substr($form_state->getTriggeringElement()['#name'], 5));
    $open_items = $form_state->get('open_items');
    if (empty($open_items[$item_id])) {
      $open_items[$item_id] = TRUE;
    } else {
      unset($open_items[$item_id]);
      /** @var \Drupal\content_hierarchy\ContentHierarchyData $dataService */
      $dataService = \Drupal::service('content_hierarchy.data');
      $children = $dataService->getChildrenOf($item_id, $this->getLangCode());
      foreach ($children as $child) {
        unset($open_items[$child]);
      }
    }
    $form_state->set('open_items', $open_items);
    $form_state->setRebuild();
  }

  public function overviewCallback($form, FormStateInterface $form_state) {
    return $form['content'];
  }

  protected function buildDraggable(&$form, $langcode, $update_tree_access, $empty) {
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
    ];

    $delta = 0;
    $weight = 0;
    $parent_fields = FALSE;
    $items = $this->getContentHierarchyStorage()->getListWithDepth($langcode, FALSE);
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
        '#prefix' => !empty($indentation) ? $this->getRenderer()->render($indentation) : '',
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
            '#markup' => $this->getDateFormatter()->format($item->getCreated(), 'short')
          ];
        }

        if (!is_null($item->getChanged())) {
          $form['content'][$key]['changed'] = [
            '#type' => 'markup',
            '#markup' => $this->getDateFormatter()->format($item->getChanged(), 'short')
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
  }

  /**
   * @inheritDoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $langcode = $form_state->cleanValues()->getValues()['tree_langcode'] ?? $this->getLanguageManager()->getDefaultLanguage()->getId();
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
        return $this->getEntityTypeManager()
          ->getStorage($content['entity_type'] . '_type')
          ->load($content['entity_bundle'])
          ->label();
      } else {
        return $this->t($content['type']);
      }
    }
    else {
      if ($content->getSource() == 'entity') {
        return $this->getEntityTypeManager()
          ->getStorage($content->getEntityType() . '_type')
          ->load($content->getEntityBundle())
          ->label();
      }
    else {
      return $this->t($content->getType());
    }
  }
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
   * @return EntityTypeManagerInterface
   */
  protected function getEntityTypeManager() {
    if (!$this->entityTypeManager) {
      $this->entityTypeManager = \Drupal::service('entity_type.manager');
    }
    return $this->entityTypeManager;
  }

  /**
   * Gets the content hierarchy storage.
   *
   * @return ContentHierarchyStorage
   *   The content hierarchy storage.
   */
  protected function getContentHierarchyStorage() {
    if (!$this->contentHierarchyStorage) {
      $this->contentHierarchyStorage = \Drupal::service('content_hierarchy.storage');
    }
    return $this->contentHierarchyStorage;
  }

  /**
   * Gets the content hierarchy data service.
   *
   * @return contentHierarchyData
   *   The content hierarchy data service.
   */
  protected function getContentHierarchyData() {
    if (!$this->contentHierarchyData) {
      $this->contentHierarchyData = \Drupal::service('content_hierarchy.data');
    }
    return $this->contentHierarchyData;
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
}
