<?php

namespace Drupal\client_webform\Plugin\views\field;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A handler to provide module status and appropriate link.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("module_status")
 */
class ModuleStatus extends FieldPluginBase {

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
   * Constructs a ModuleStatus object.
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
    // This field doesn't need to modify the query.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $node = $this->getEntity($values);

    if (!$node || $node->bundle() !== 'module') {
      return '';
    }

    // Get the webform ID from the module.
    $webform_id = NULL;
    if ($node->hasField('field_form') && !$node->get('field_form')->isEmpty()) {
      $webform_id = $node->get('field_form')->target_id;
    }

    // Default status and URL.
    $status = 'not-started';
    $status_label = $this->t('Not Started');
    $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()]);

    // Check for user's submission if webform exists.
    if ($webform_id) {
      $submission = $this->getUserSubmission($webform_id);

      if ($submission) {
        // Check if completed.
        if ($submission->get('completed')->value > 0) {
          $status = 'completed';
          $status_label = $this->t('Completed');
        }
        else {
          $status = 'in-progress';
          $status_label = $this->t('In Progress');
        }

        // Link to the submission edit form.
        $url = Url::fromRoute('entity.webform_submission.edit_form', [
          'webform' => $webform_id,
          'webform_submission' => $submission->id(),
        ]);
      }
    }

    return [
      '#theme' => 'module_status_field',
      '#status' => $status,
      '#status_label' => $status_label,
      '#url' => $url,
      '#cache' => [
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * Get the user's submission for a webform.
   *
   * @param string $webform_id
   *   The webform ID.
   *
   * @return \Drupal\webform\WebformSubmissionInterface|null
   *   The submission or NULL.
   */
  protected function getUserSubmission($webform_id) {
    $submissions = $this->entityTypeManager
      ->getStorage('webform_submission')
      ->getQuery()
      ->condition('webform_id', $webform_id)
      ->condition('uid', $this->currentUser->id())
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($submissions)) {
      return $this->entityTypeManager
        ->getStorage('webform_submission')
        ->load(reset($submissions));
    }

    return NULL;
  }

}
