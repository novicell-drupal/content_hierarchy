<?php

namespace Drupal\content_hierarchy\Form;

use Drupal\content_hierarchy\ContentHierarchy;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class ContentOverviewController.
 */
class ContentHierarchySortingForm extends ContentHierarchyOverviewBase {

  /**
   * @inheritDoc
   */
  public function getFormId() {
    return 'content_sorting_form';
  }

  /**
   * @return string
   */
  public function getRouteName() {
    return 'content_hierarchy.content_sorting';
  }
  /**
   * Return sorting form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $update_tree_access = TRUE;
    $langcode = $this->getLangCode();

    $form_state->set(['content_hierarchy', 'langcode'], $langcode);
    $parent_fields = FALSE;

    $page = $this->getPagerManager()->findPage();
    // Number of items per page.
    $page_increment = $this->config('content_hierarchy.hierarchy_settings')->get('contents_per_page_admin') ?? 50;
    // Elements shown on this page.
    $page_entries = 0;
    // Elements at the root level before this page.
    $before_entries = 0;
    // Elements at the root level after this page.
    $after_entries = 0;
    // Elements at the root level on this page.
    $root_entries = 0;

    // Contents from previous and next pages are shown if the content tree would have
    // been cut in the middle. Keep track of how many extra content we show on
    // each page of content.
    $back_step = NULL;
    $forward_step = 0;

    // An array of the content to be displayed on this page.
    /** @var ContentHierarchy[] $current_page */
    $current_page = [];

    $delta = 0;
    $content_deltas = [];
    $tree = $this->contentHierarchyStorage->getListWithDepth($langcode, FALSE);
    $tree_index = 0;
    do {
      // In case this tree is completely empty.
      if (empty($tree[$tree_index])) {
        break;
      }
      $delta++;
      // Count entries before the current page.
      if ($page && ($page * $page_increment) > $before_entries && !isset($back_step)) {
        $before_entries++;
        continue;
      }
      // Count entries after the current page.
      elseif ($page_entries > $page_increment && isset($complete_tree)) {
        $after_entries++;
        continue;
      }

      // Do not let an item start the page that is not at the root.
      $content = $tree[$tree_index];
      if (($content->getDepth() > 0) && !isset($back_step)) {
        $back_step = 0;
        while ($pcontent = $tree[--$tree_index]) {
          $before_entries--;
          $back_step++;
          if ($pcontent->getDepth() == 0) {
            $tree_index--;
            // Jump back to the start of the root level parent.
            continue 2;
          }
        }
      }
      $back_step = isset($back_step) ? $back_step : 0;

      // Continue rendering the tree until we reach the a new root item.
      if ($page_entries >= $page_increment + $back_step + 1 && $content->getDepth() == 0 && $root_entries > 1) {
        $complete_tree = TRUE;
        // This new item at the root level is the first item on the next page.
        $after_entries++;
        continue;
      }
      if ($page_entries >= $page_increment + $back_step) {
        $forward_step++;
      }

      // Finally, if we've gotten down this far, we're rendering an item on this
      // page.
      $page_entries++;
      $content_deltas[$content->id()] = isset($content_deltas[$content->id()]) ? $content_deltas[$content->id()] + 1 : 0;
      $key = 'tid:' . $content->id() . ':' . $content_deltas[$content->id()];

      // Keep track of the first content displayed on this page.
      if ($page_entries == 1) {
        $form['#first_tid'] = $content->id();
      }
      // Keep a variable to make sure at least 2 root elements are displayed.
      if ($content->getParentId() == 0) {
        $root_entries++;
      }
      $current_page[$key] = $content;
    } while (isset($tree[++$tree_index]));

    // Because we didn't use a pager query, set the necessary pager variables.
    $total_entries = $before_entries + $page_entries + $after_entries;
    $this->getPagerManager()->createPager($total_entries, $page_increment);

    // If this form was already submitted once, it's probably hit a validation
    // error. Ensure the form is rebuilt in the same order as the user
    // submitted.
    $user_input = $form_state->getUserInput();
    if (!empty($user_input)) {
      // Get the POST order.
      $order = array_flip(array_keys($user_input['contents']));
      // Update our form with the new order.
      $current_page = array_merge($order, $current_page);
      foreach ($current_page as $key => $content) {
        // Verify this is content for the current page and set at the current
        // depth.
        if (is_array($user_input['contents'][$key]) && is_numeric($user_input['contents'][$key]['content']['tid'])) {
          $current_page[$key]->setDepth($user_input['contents'][$key]['content']['depth']);
        }
        else {
          unset($current_page[$key]);
        }
      }
    }

    $errors = $form_state->getErrors();
    $row_position = 0;
    // Build the actual form.
    $form['contents'] = [
      '#type' => 'table',
      '#empty' => $this->t('No content available.'),
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

    foreach ($current_page as $key => $content) {
      $form['contents'][$key] = [
        'content' => [],
        'type' => [],
        'status' => [],
        'created' => [],
        'changed' => [],
        'operations' => [],
        'weight' => $update_tree_access ? [] : NULL,
      ];
      $form['contents'][$key]['#content'] = $content;
      $indentation = [];
      if ($content->getDepth() > 0) {
        $indentation = [
          '#theme' => 'indentation',
          '#size' => $content->getDepth(),
        ];
      }
      $form['contents'][$key]['content'] = [
        '#prefix' => !empty($indentation) ? $this->renderer->render($indentation) : '',
        '#type' => 'link',
        '#title' => $content->getTitle(),
        '#url' => $content->getUrl(),
      ];

      $form['contents'][$key]['#attributes']['class'] = [];
      if ($update_tree_access && count($tree) > 1) {
        $parent_fields = TRUE;
        $form['contents'][$key]['content']['tid'] = [
          '#type' => 'hidden',
          '#value' => $content->id(),
          '#attributes' => [
            'class' => ['content-id'],
          ],
        ];
        $form['contents'][$key]['content']['parent'] = [
          '#type' => 'hidden',
          // Yes, default_value on a hidden. It needs to be changeable by the
          // javascript.
          '#default_value' => $content->getParentId(),
          '#attributes' => [
            'class' => ['content-parent'],
          ],
        ];
        $form['contents'][$key]['content']['depth'] = [
          '#type' => 'hidden',
          // Same as above, the depth is modified by javascript, so it's a
          // default_value.
          '#default_value' => $content->getDepth(),
          '#attributes' => [
            'class' => ['content-depth'],
          ],
        ];
      }

      $form['contents'][$key]['type'] = [
        '#type' => 'markup',
        '#markup' => $this->getTypeLabel($content)
      ];

      if (!is_null($content->getStatus())) {
        $form['contents'][$key]['status'] = [
          '#type' => 'markup',
          '#markup' => $this->t($content->getStatus()),
        ];
      }

      if (!is_null($content->getCreated())) {
        $form['contents'][$key]['created'] = [
          '#type' => 'markup',
          '#markup' => $this->getDateFormatter()->format($content->getCreated(), 'short')
        ];
      }

      if (!is_null($content->getChanged())) {
        $form['contents'][$key]['changed'] = [
          '#type' => 'markup',
          '#markup' => $this->getDateFormatter()->format($content->getChanged(), 'short')
        ];
      }

      if ($update_tree_access) {
        $form['contents'][$key]['weight'] = [
          '#type' => 'weight',
          '#delta' => $delta,
          '#title' => $this->t('Weight for content'),
          '#title_display' => 'invisible',
          '#default_value' => $content->getWeight(),
          '#attributes' => ['class' => ['content-weight']],
        ];
      }

      if ($operations = $content->getOperations()) {
        $form['contents'][$key]['operations'] = [
          '#type' => 'operations',
          '#links' => $operations,
        ];
      }

      if ($parent_fields) {
        $form['contents'][$key]['#attributes']['class'][] = 'draggable';
      }

      // Add classes that mark which content belong to previous and next pages.
      if ($row_position < $back_step || $row_position >= $page_entries - $forward_step) {
        $form['contents'][$key]['#attributes']['class'][] = 'taxonomy-term-preview';
      }

      if ($row_position !== 0 && $row_position !== count($tree) - 1) {
        if ($row_position == $back_step - 1 || $row_position == $page_entries - $forward_step - 1) {
          $form['contents'][$key]['#attributes']['class'][] = 'taxonomy-term-divider-top';
        }
        elseif ($row_position == $back_step || $row_position == $page_entries - $forward_step) {
          $form['contents'][$key]['#attributes']['class'][] = 'taxonomy-term-divider-bottom';
        }
      }

      // Add an error class if this row contains a form error.
      foreach ($errors as $error_key => $error) {
        if (strpos($error_key, $key) === 0) {
          $form['contents'][$key]['#attributes']['class'][] = 'error';
        }
      }
      $row_position++;
    }

    if ($update_tree_access) {
      if ($parent_fields) {
        $form['contents']['#tabledrag'][] = [
          'action' => 'match',
          'relationship' => 'parent',
          'group' => 'content-parent',
          'subgroup' => 'content-parent',
          'source' => 'content-id',
          'hidden' => FALSE,
        ];
        $form['contents']['#tabledrag'][] = [
          'action' => 'depth',
          'relationship' => 'group',
          'group' => 'content-depth',
          'hidden' => FALSE,
        ];
        $form['contents']['#attached']['library'][] = 'taxonomy/drupal.taxonomy';
        $form['contents']['#attached']['drupalSettings']['taxonomy'] = [
          'backStep' => $back_step,
          'forwardStep' => $forward_step,
        ];
      }
      $form['contents']['#tabledrag'][] = [
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => 'content-weight',
      ];
    }

    if ($update_tree_access && count($tree) > 1) {
      $form['actions'] = ['#type' => 'actions', '#tree' => FALSE];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
        '#button_type' => 'primary',
      ];
    }

    $form['pager_pager'] = ['#type' => 'pager'];
    return $form;
  }

  /**
   * Form submission handler.
   *
   * Rather than using a textfield or weight field, this form depends entirely
   * upon the order of form elements on the page to determine new weights.
   *
   * Because there might be hundreds or thousands of content items that need to
   * be ordered, content is weighted from 0 to the number of items in the
   * tree, rather than the standard -10 to 10 scale. Numbers are sorted
   * lowest to highest, but are not necessarily sequential. Numbers may be
   * skipped when an item has children so that reordering is minimal when a child
   * is added or removed from an item.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Sort content order based on weight.
    uasort($form_state->getValue('contents'), ['Drupal\Component\Utility\SortArray', 'sortByWeightElement']);

    $langcode = $form_state->get(['content_hierarchy', 'langcode']);
    $changed_content = [];
    $tree = $this->contentHierarchyStorage->getListWithDepth($langcode, FALSE);

    if (empty($tree)) {
      return;
    }

    // Build a list of all items that need to be updated on previous pages.
    $weight = 0;
    $content = $tree[0];
    while ($content->id() != $form['#first_tid']) {
      if ($content->getParentId() == 0 && $content->getWeight() != $weight) {
        $content->setWeight($weight);
        $changed_content[$content->id()] = $content;
      }
      $weight++;
      $content = $tree[$weight];
    }

    // Renumber the current page weights and assign any new parents.
    $level_weights = [];
    foreach ($form_state->getValue('contents') as $tid => $values) {
      if (isset($form['contents'][$tid]['#content'])) {
        /** @var ContentHierarchy $content */
        $content = $form['contents'][$tid]['#content'];
        // Give content at the root level a weight in sequence with content on previous pages.
        if ($values['content']['parent'] == 0 && $content->getWeight() != $weight) {
          $content->setWeight($weight);
          $changed_content[$content->id()] = $content;
        }
        // Content not at the root level can safely start from 0 because they're all on this page.
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

    // Build a list of all content that need to be updated on following pages.
    for ($weight; $weight < count($tree); $weight++) {
      $content = $tree[$weight];
      if ($content->getParentId() == 0 && $content->getWeight() != $weight) {
        $content->setWeight($weight);
        $changed_content[$content->id()] = $content;
      }
    }

    if (!empty($changed_content)) {
      // Save all updated content.
      $this->contentHierarchyStorage->saveMultiple($changed_content);

      $this->messenger()->addStatus($this->t('The configuration options have been saved.'));
    }
  }
}
