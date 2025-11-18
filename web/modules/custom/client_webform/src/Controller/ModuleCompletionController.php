<?php

namespace Drupal\client_webform\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\client_webform\WebformClientManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the module completion page.
 */
class ModuleCompletionController extends ControllerBase {

  /**
   * The webform client manager.
   *
   * @var \Drupal\client_webform\WebformClientManager
   */
  protected $clientManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ModuleCompletionController object.
   *
   * @param \Drupal\client_webform\WebformClientManager $client_manager
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
      $container->get('client_webform.manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Displays the module completion page.
   *
   * @param string $webform_submission
   *   The webform submission ID.
   *
   * @return array
   *   A render array.
   */
  public function view($webform_submission) {
    // Load the submission.
    $submission = $this->entityTypeManager
      ->getStorage('webform_submission')
      ->load($webform_submission);

    if (!$submission) {
      throw new NotFoundHttpException();
    }

    // Verify the current user owns this submission.
    if ($submission->getOwnerId() != $this->currentUser()->id()) {
      throw new NotFoundHttpException();
    }

    $webform_id = $submission->getWebform()->id();

    // Get the module node associated with this webform.
    $module_node = $this->getModuleForWebform($webform_id);
    $module_title = $module_node ? $module_node->getTitle() : $this->t('Module');

    // Check if there's a next module.
    $next_webform_id = $this->clientManager->getNextWebform($webform_id);
    $has_next_module = !empty($next_webform_id);

    // Build URL for the next module button.
    $next_module_url = NULL;

    if ($has_next_module) {
      // Get the Module node for the next webform.
      $next_module_node = $this->getModuleForWebform($next_webform_id);
      if ($next_module_node) {
        $next_module_url = Url::fromRoute('entity.node.canonical', ['node' => $next_module_node->id()]);
      }
    }

    return [
      '#theme' => 'webform_module_completion',
      '#module_title' => $module_title,
      '#next_module_url' => $next_module_url,
      '#has_next_module' => $has_next_module,
    ];
  }

  /**
   * Get the Module node associated with a webform.
   *
   * @param string $webform_id
   *   The webform ID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The module node or NULL.
   */
  protected function getModuleForWebform($webform_id) {
    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'module')
      ->condition('field_form', $webform_id)
      ->range(0, 1)
      ->accessCheck(FALSE);

    $nids = $query->execute();

    if (!empty($nids)) {
      return $this->entityTypeManager
        ->getStorage('node')
        ->load(reset($nids));
    }

    return NULL;
  }

}
