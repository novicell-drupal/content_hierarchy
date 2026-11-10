<?php

namespace Drupal\Tests\content_hierarchy\Kernel;

use Drupal\content_hierarchy\Form\ContentHierarchySettingsForm;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests creating and building the settings form using core services.
 *
 * @group content_hierarchy
 */
#[Group('content_hierarchy')]
#[RunTestsInSeparateProcesses]
class SettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'node', 'text', 'filter', 'content_hierarchy'];

  /**
   * The existing hierarchy settings do not have configuration schema.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Tests that the form retains its configured defaults and core actions.
   */
  public function testSettingsForm(): void {
    $this->config('content_hierarchy.hierarchy_settings')
      ->set('contents_per_page_admin', 100)
      ->set('multilingual', FALSE)
      ->save();
    $form = ContentHierarchySettingsForm::create($this->container)
      ->buildForm([], new FormState());

    $this->assertSame(100, $form['contents_per_page_admin']['#default_value']);
    $this->assertFalse($form['multilingual']['#default_value']);
    $this->assertSame('checkboxes', $form['entity_bundles']['node']['#type']);
    $this->assertSame('submit', $form['actions']['submit']['#type']);
  }

}
