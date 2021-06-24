<?php

namespace Drupal\content_hierarchy\Form;

use Drupal\Core\Form\FormStateInterface;

class ContentHierarchyOverviewForm extends ContentHierarchyOverviewBase {

  /**
   * @inheritDoc
   */
  public function getFormId() {
    return 'content_overview_form';
  }

  /**
   * @return string
   */
  public function getRouteName() {
    return 'content_hierarchy.content_overview';
  }

  /**
   * Return overview page.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $update_tree_access = TRUE;
    $langcode = $this->getLangCode();

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
      '#empty' => $this->t('No content available.'),
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

    return $form;
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

  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
