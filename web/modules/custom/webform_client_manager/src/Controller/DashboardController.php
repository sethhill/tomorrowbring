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

    // Get enabled modules for this client.
    $enabled_modules = $this->clientManager->getEnabledWebforms();

    if (empty($enabled_modules)) {
      return [
        '#markup' => $this->t('No modules have been assigned to your client yet.'),
      ];
    }

    // Build module cards.
    $modules = [];
    foreach ($enabled_modules as $webform_id) {
      $webform = $this->entityTypeManager->getStorage('webform')->load($webform_id);

      if (!$webform) {
        continue;
      }

      // Check if user has completed this module and get submission ID.
      $submission_id = $this->getModuleSubmission($webform_id, $current_user->id());
      $is_completed = !empty($submission_id);

      // Extract module number and description.
      $title = $webform->label();
      $module_number = $this->extractModuleNumber($title);
      $module_name = $this->extractModuleName($title);

      // Get description from webform intro if available.
      $description = $webform->get('description') ?: $this->t('Complete this module to continue.');
      $description = '';

      // Determine URL based on completion status.
      if ($is_completed && $submission_id) {
        // Link to the submission for review (prepopulated).
        $url = Url::fromRoute('entity.webform_submission.canonical', [
          'webform' => $webform_id,
          'webform_submission' => $submission_id,
        ]);
      }
      else {
        // Link to start new submission.
        $url = Url::fromRoute('entity.webform.canonical', ['webform' => $webform_id]);
      }

      $modules[] = [
        'id' => $webform_id,
        'number' => $module_number,
        'name' => $module_name,
        'title' => $title,
        'description' => $description,
        'url' => $url,
        'completed' => $is_completed,
        'submission_id' => $submission_id,
      ];
    }

    // Calculate progress.
    $total = count($modules);
    $completed = count(array_filter($modules, function ($module) {
      return $module['completed'];
    }));
    $progress_percentage = $total > 0 ? round(($completed / $total) * 100) : 0;

    return [
      '#theme' => 'webform_client_dashboard',
      '#client_name' => $client->label(),
      '#modules' => $modules,
      '#total_modules' => $total,
      '#completed_modules' => $completed,
      '#progress_percentage' => $progress_percentage,
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
   *   The submission ID if completed, NULL otherwise.
   */
  protected function getModuleSubmission($webform_id, $uid) {
    $submissions = $this->entityTypeManager
      ->getStorage('webform_submission')
      ->getQuery()
      ->condition('webform_id', $webform_id)
      ->condition('uid', $uid)
      ->condition('completed', 0, '>')
      ->sort('completed', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    return !empty($submissions) ? reset($submissions) : NULL;
  }

  /**
   * Extract module number from title.
   *
   * @param string $title
   *   The webform title.
   *
   * @return int
   *   The module number.
   */
  protected function extractModuleNumber($title) {
    if (preg_match('/^Module (\d+):/i', $title, $matches)) {
      return (int) $matches[1];
    }
    return 0;
  }

  /**
   * Extract module name from title.
   *
   * @param string $title
   *   The webform title.
   *
   * @return string
   *   The module name.
   */
  protected function extractModuleName($title) {
    if (preg_match('/^Module \d+:\s*(.+)$/i', $title, $matches)) {
      return $matches[1];
    }
    return $title;
  }

}
