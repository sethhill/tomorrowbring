<?php

namespace Drupal\webform_client_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\webform_client_manager\WebformClientManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Controller for the member dashboard.
 */
class DashboardController extends ControllerBase {

  /**
   * The webform client manager.
   *
   * @var \Drupal\webform_client_manager\WebformClientManager
   */
  protected $clientManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a DashboardController object.
   *
   * @param \Drupal\webform_client_manager\WebformClientManager $client_manager
   *   The webform client manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(WebformClientManager $client_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->clientManager = $client_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('webform_client_manager.manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Displays the member dashboard.
   *
   * @return array
   *   A render array.
   */
  public function view() {
    $current_user = $this->currentUser();

    // Get the user's client.
    $client = $this->clientManager->getCurrentUserClient();

    if (!$client) {
      return [
        '#markup' => $this->t('You have not been assigned to a client. Please contact an administrator.'),
      ];
    }

    // Get enabled Module nodes for this client.
    $enabled_module_nids = $this->clientManager->getEnabledModules();

    if (empty($enabled_module_nids)) {
      return [
        '#markup' => $this->t('No modules have been assigned to your client yet.'),
      ];
    }

    // Load Module nodes to calculate progress.
    $module_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($enabled_module_nids);

    // Calculate progress.
    $total = count($module_nodes);
    $completed = 0;

    foreach ($module_nodes as $node) {
      // Get the webform associated with this module.
      $webform_id = NULL;
      if ($node->hasField('field_form') && !$node->get('field_form')->isEmpty()) {
        $webform_id = $node->get('field_form')->target_id;
      }

      // Check if user has completed this module.
      if ($webform_id) {
        $submission_id = $this->getModuleSubmission($webform_id, $current_user->id());
        if ($submission_id) {
          $submission = $this->entityTypeManager->getStorage('webform_submission')->load($submission_id);
          if ($submission && $submission->get('completed')->value > 0) {
            $completed++;
          }
        }
      }
    }

    $progress_percentage = $total > 0 ? round(($completed / $total) * 100) : 0;

    // Build the view with enabled module NIDs as arguments.
    $view = \Drupal\views\Views::getView('client_modules');
    $view->setDisplay('block_1');
    $view->setArguments([implode('+', $enabled_module_nids)]);
    $view_render = $view->render();

    return [
      '#theme' => 'webform_client_dashboard',
      '#client_name' => $client->getTitle(),
      '#total_modules' => $total,
      '#completed_modules' => $completed,
      '#progress_percentage' => $progress_percentage,
      '#modules_view' => $view_render,
      '#attached' => [
        'library' => [
          'webform_client_manager/dashboard',
        ],
      ],
    ];
  }

  /**
   * Get the most recent submission for a module by the user.
   *
   * @param string $webform_id
   *   The webform ID.
   * @param int $uid
   *   The user ID.
   *
   * @return int|null
   *   The submission ID if exists (draft or completed), NULL otherwise.
   */
  protected function getModuleSubmission($webform_id, $uid) {
    // Get any submission (draft or completed) by this user for this webform.
    $submissions = $this->entityTypeManager
      ->getStorage('webform_submission')
      ->getQuery()
      ->condition('webform_id', $webform_id)
      ->condition('uid', $uid)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    return !empty($submissions) ? reset($submissions) : NULL;
  }

}
