<?php

namespace Drupal\content_hierarchy\Plugin\Field\FieldWidget;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\content_hierarchy\ContentHierarchyData;
use Drupal\content_hierarchy\ContentHierarchyStorage;
use Drupal\content_hierarchy\ContentHierarchyWidgets;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Modal widget.
 *
 * @FieldWidget(
 *   id = "content_hierarchy_modal",
 *   label = @Translation("Content Hierarchy modal"),
 *   description = @Translation("A widget that opens a modal to select an item in the tree."),
 *   field_types = {
 *     "content_hierarchy"
 *   }
 * )
 */
class ContentHierarchyModal extends WidgetBase implements ContainerFactoryPluginInterface {

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

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $entity = $items->getEntity();
    $placement = isset($items[$delta]->value) ? $items[$delta]->value : -1;

    if(!$entity->isNew()) {
      $langcode = $entity->language()->getId();
      $content_id = $this->data->findEntity($entity);
      $placement = $this->data->getContentPlacement($content_id, $langcode);
    } else {
      $langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
      $content_id = NULL;
    }

    $element += [
      '#type' => 'details',
      '#open' => $entity->isNew(),
      '#attributes' => ['class' => ['content-hierarchy-modal']]
    ];

    // Put the form element into the form's "advanced" group.
    $element['#group'] = 'advanced';

    $element['widget']= $this->widgets->buildModalWidget($langcode, $content_id, $placement);

    $element['new_parent'] = [
      '#type' => 'hidden',
      '#default_value' => $placement,
    ];

    $element += [
      '#attached' => [
        'library' => [
          'content_hierarchy/contentHierarchyModal',
        ],
      ],
    ];

    return $element;
  }

}
