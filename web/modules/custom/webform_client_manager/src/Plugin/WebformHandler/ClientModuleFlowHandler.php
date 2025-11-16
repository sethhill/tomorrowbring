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
    $current_webform_id = $this->getWebform()->id();

    // Check if there's a next webform.
    $next_webform_id = $this->clientManager->getNextWebform($current_webform_id);

    if ($next_webform_id) {
      // Redirect to next webform.
      $url = Url::fromRoute('entity.webform.canonical', ['webform' => $next_webform_id]);
      $form_state->setRedirectUrl($url);
    }
    else {
      // This is the last webform, check for custom redirect.
      $redirect_url = $this->clientManager->getCompletionRedirectUrl();

      if ($redirect_url) {
        // Use custom redirect URL.
        try {
          $url = Url::fromUri($redirect_url);
          $form_state->setRedirectUrl($url);
        }
        catch (\Exception $e) {
          // If URL is invalid, let default confirmation page show.
          \Drupal::logger('webform_client_manager')->error('Invalid completion redirect URL: @url', ['@url' => $redirect_url]);
        }
      }
      // If no redirect URL, let the default webform confirmation page show.
    }
  }

}
