<?php

namespace Drupal\content_hierarchy\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\NodeType;

class ContentHierarchySettingsForm extends ConfigFormBase {

  /**
   * Gets the configuration names that will be editable.
   *
   * @return array
   *   An array of configuration object names that are editable if called in
   *   conjunction with the trait's config() method.
   */
  protected function getEditableConfigNames() {
    return [
      'content_hierarchy.hierarchy_settings'
    ];
  }

  /**
   * Returns a unique string identifying the form.
   *
   * The returned ID should be a unique string that can be a valid PHP function
   * name, since it's used in hook implementation names such as
   * hook_form_FORM_ID_alter().
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'content_hierarchy_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('content_hierarchy.hierarchy_settings');

    $form['#tree'] = TRUE;
    $form['settings'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Content Hierarchy settings'),
      '#description' => $this->t(
        'Configure how the content hierarchy works and what nodes are included in the hierarchy lists.'
      ),
      '#prefix' => '<div id="table-wrapper">',
      '#suffix' => '</div>',
    ];

    $options = [];
    foreach(NodeType::loadMultiple() as $id => $node_type) {
      $options[$id] = $node_type->label();
    }
    $form['settings']['ignored_nodes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Ignore these nodes on the content lists'),
      '#options' => $options,
      '#default_value' => $config->get('ignored_nodes') ?? []
    ];

    $form['node_403'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Default 403 (access denied) page'),
      '#description' => $this->t('Default 403 (access denied) page'),
      '#target_type' => 'node'
    ];
    if (!empty($config->get('node_403'))) {
      $entity = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->load($config->get('node_403'));
      $form['node_403']['#default_value'] = $entity;
    }

    $form['node_404'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Default 404 (not found) page'),
      '#description' => $this->t('Default 404 (not found) page'),
      '#target_type' => 'node'
    ];
    if (!empty($config->get('node_404'))) {
      $entity = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->load($config->get('node_404'));
      $form['node_404']['#default_value'] = $entity;
    }

    return parent::buildform($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('content_hierarchy.hierarchy_settings');
    $values = $form_state->cleanValues()->getValues();
    $ignored_nodes = [];
    if (isset($values['settings']['ignored_nodes'])) {

      foreach ($values['settings']['ignored_nodes'] as $key => $value) {
        if ($value !== 0) {
          $ignored_nodes[$key] = $value;
        }
      }

      $config->set('ignored_nodes', $ignored_nodes);
    }
    $config->set('node_403', $values['node_403']);
    $config->set('node_404', $values['node_404']);
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
