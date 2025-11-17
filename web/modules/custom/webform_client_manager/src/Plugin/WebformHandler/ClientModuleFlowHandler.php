<?php

namespace Drupal\webform_client_manager\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform_client_manager\WebformClientManager;
use Drupal\Core\Url;

/**
 * Webform handler for managing client module flow.
 *
 * @WebformHandler(
 *   id = "client_module_flow",
 *   label = @Translation("Client Module Flow"),
 *   category = @Translation("Flow"),
 *   description = @Translation("Redirects users to the next enabled module after submission."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class ClientModuleFlowHandler extends WebformHandlerBase {

  /**
   * The webform client manager.
   *
   * @var \Drupal\webform_client_manager\WebformClientManager
   */
  protected $clientManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->clientManager = $container->get('webform_client_manager.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function confirmForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    // Redirect to our custom module completion page.
    $url = Url::fromRoute('webform_client_manager.module_completion', [
      'webform_submission' => $webform_submission->id(),
    ]);
    $form_state->setRedirectUrl($url);
  }

}
