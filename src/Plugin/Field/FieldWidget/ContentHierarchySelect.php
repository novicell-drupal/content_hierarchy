<?php

namespace Drupal\content_hierarchy\Plugin\Field\FieldWidget;

use Drupal\content_hierarchy\ContentHierarchy;
use Drupal\content_hierarchy\ContentHierarchyData;
use Drupal\content_hierarchy\ContentHierarchyStorage;
use Drupal\content_hierarchy\ContentHierarchyWidgets;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Select widget.
 *
 * @FieldWidget(
 *   id = "content_hierarchy_select",
 *   label = @Translation("Content Hierarchy select"),
 *   description = @Translation("A select field sorted by hierarchy tree."),
 *   field_types = {
 *     "content_hierarchy"
 *   }
 * )
 */
class ContentHierarchySelect extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * Content Hierarchy data service
   *
   * @var \Drupal\content_hierarchy\ContentHierarchyData
   */
  protected $data;

  /**
   * Content Hierarchy storage service
   *
   * @var \Drupal\content_hierarchy\ContentHierarchyStorage
   */
  protected $storage;

  /**
   * Content Hierarchy widgets service
   *
   * @var \Drupal\content_hierarchy\ContentHierarchyWidgets
   */
  protected $widgets;

  /**
   * DdsEntityReferenceHierarchySelect constructor.
   *
   * @param                                                                   $plugin_id
   * @param                                                                   $plugin_definition
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   * @param array $settings
   * @param array $third_party_settings
   * @param \Drupal\content_hierarchy\ContentHierarchyStorage $storage
   * @param \Drupal\content_hierarchy\ContentHierarchyData $data
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, ContentHierarchyStorage $storage, ContentHierarchyData $data, ContentHierarchyWidgets $widgets) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->storage = $storage;
    $this->data = $data;
    $this->widgets = $widgets;
  }

  /**
   * Creates an instance of the plugin.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container to pull out services used in the plugin.
   * @param array                                                     $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string                                                    $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed                                                     $plugin_definition
   *   The plugin implementation definition.
   *
   * @return static
   *   Returns an instance of this plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('content_hierarchy.storage'),
      $container->get('content_hierarchy.data'),
      $container->get('content_hierarchy.widgets')
    );
  }

  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $entity = $items->getEntity();
    $placement = isset($items[$delta]->value) ? $items[$delta]->value : -1;
    $content_id = NULL;

    if(!$entity->isNew()) {
      $langcode = $entity->language()->getId();
      $content_id = $this->data->findEntity($entity);
      $placement = $this->data->getContentPlacement($content_id, $langcode);
    } else {
      $langcode = 'und';
    }

    $element += [
      '#type' => 'details',
      '#open' => $entity->isNew(),
      'settings' => []
    ];

    // Put the form element into the form's "advanced" group.
    $element['#group'] = 'advanced';

    if ($entity->isNew() || $placement === NULL || $placement > -2) {
      $element += [
        '#attached' => [
          'library' => [
            'content_hierarchy/contentHierarchySelect',
          ],
        ],
      ];
      $element['new_parent'] = [
        '#attributes' => ['class' => ['content-hierarchy-select']],
        '#type' => 'select',
        '#default_value' => $placement,
        '#options' => $this->widgets->getAllOptions($langcode),
        '#element_validate' => [
          [$this, 'validate'],
        ],
      ];
      $element['current_parent'] = array(
        '#attributes' => ['class' => ['content-hierarchy-current']],
        '#type' => 'hidden',
        '#default_value' => json_encode([
          'langcode' => $langcode,
          'content_id' => $content_id,
        ])
      );
    } else {
      $content = $this->storage->load($content_id, $langcode);
      $element['description'] = [
        '#type' => 'item',
        '#title' => $this->t('Placement: @placement', ['@placement' => $this->widgets->placementToText($content->getPlacement(), $langcode)]),
        '#description' => $this->t('Can be changed in the content overview page')
      ];
    }

    return $element;
  }

  /**
   * Validate the content hierarchy field.
   */
  public function validate($element, FormStateInterface $form_state) {
    dpm($element);
    dpm($form_state->getValues());
    // TODO: Validate if a position creates endless loops
  }

}
