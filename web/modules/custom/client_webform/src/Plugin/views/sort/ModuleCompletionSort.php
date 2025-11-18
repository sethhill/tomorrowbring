<?php

namespace Drupal\client_webform\Plugin\views\sort;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\views\Plugin\views\sort\SortPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sort handler for module completion status.
 *
 * Sorts modules by completion status (incomplete first, then completed).
 *
 * @ingroup views_sort_handlers
 *
 * @ViewsSort("module_completion_sort")
 */
class ModuleCompletionSort extends SortPluginBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ModuleCompletionSort object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $this->ensureMyTable();

    // First, join to the field_form table to get the webform_id.
    $field_form_configuration = [
      'table' => 'node__field_form',
      'field' => 'entity_id',
      'left_table' => 'node_field_data',
      'left_field' => 'nid',
      'operator' => '=',
    ];

    $field_form_join = \Drupal::service('plugin.manager.views.join')
      ->createInstance('standard', $field_form_configuration);

    $this->query->addRelationship('node__field_form', $field_form_join, 'node_field_data');

    // Then join to webform_submission using the webform_id and current user.
    $submission_configuration = [
      'table' => 'webform_submission',
      'field' => 'webform_id',
      'left_table' => 'node__field_form',
      'left_field' => 'field_form_target_id',
      'operator' => '=',
      'extra' => [
        [
          'field' => 'uid',
          'value' => $this->currentUser->id(),
        ],
      ],
    ];

    $submission_join = \Drupal::service('plugin.manager.views.join')
      ->createInstance('standard', $submission_configuration);

    $this->query->addRelationship('webform_submission_sort', $submission_join, 'node__field_form');

    // Add the sort on the completed field.
    // NULL (no submission) or 0 (not completed) should come before 1 (completed).
    // We use COALESCE to treat NULL as 0.
    $formula = 'COALESCE(webform_submission_sort.completed, 0)';
    $this->query->addOrderBy(NULL, $formula, $this->options['order'], 'module_completion_status');
  }

}
