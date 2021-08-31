<?php

namespace Drupal\content_hierarchy\Form;

use Drupal\content_hierarchy\ContentHierarchy;
use Drupal\content_hierarchy\ContentHierarchyData;
use Drupal\content_hierarchy\ContentHierarchyStorage;
use Drupal\content_hierarchy\ContentHierarchyWidgets;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class ContentHierarchyModalForm extends FormBase {

  /**
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * @var \Drupal\content_hierarchy\ContentHierarchyData
   */
  protected $widgets;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('language_manager'),
      $container->get('content_hierarchy.widgets'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(RequestStack $requestStack, LanguageManagerInterface $languageManager, ContentHierarchyWidgets $contentHierarchyWidgets) {
    $this->request = $requestStack->getCurrentRequest();
    $this->languageManager = $languageManager;
    $this->widgets = $contentHierarchyWidgets;
  }

  public function getFormId() {
    return 'content_hierarchy_modal_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $langcode = $this->request->get('langcode');
    $content_id = $this->request->get('content_id');

    if ($langcode == 'undefined') {
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
    }
    if ($content_id == 0) {
      $content_id = NULL;
    }

    $form += [
      '#attached' => [
        'library' => [
          'core/drupal.dialog.ajax',
        ],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['content-hierarchy-modal__actions']]
    ];
    $form['actions']['root'] = [
      '#title' => $this->t('Make root'),
      '#type' => 'link',
      '#url' => Url::fromRoute('content_hierarchy.modal.select', [
        'id' => 0,
        'langcode' => $langcode,
        'content_id' => $content_id ?? 0
      ]),
      '#attributes' => [
        'class' => ['use-ajax', 'button'],
      ],
    ];
    $form['actions']['excluded'] = [
      '#title' => $this->t('Exclude'),
      '#type' => 'link',
      '#url' => Url::fromRoute('content_hierarchy.modal.select', [
        'id' => -1,
        'langcode' => $langcode,
        'content_id' => $content_id ?? 0
      ]),
      '#attributes' => [
        'class' => ['use-ajax', 'button'],
      ],
    ];

    $form += $this->widgets->getInitialModalItems($langcode, $content_id);
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // TODO: Implement submitForm() method.
  }

}
