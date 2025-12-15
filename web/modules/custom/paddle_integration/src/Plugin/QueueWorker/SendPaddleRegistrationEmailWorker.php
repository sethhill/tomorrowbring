<?php

namespace Drupal\paddle_integration\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\paddle_integration\PaddleCustomerManager;
use Psr\Log\LoggerInterface;
use Drupal\Core\Mail\MailManagerInterface;

/**
 * Processes registration email sending tasks in the background.
 *
 * @QueueWorker(
 *   id = "paddle_registration_emails",
 *   title = @Translation("Paddle Registration Email Sender"),
 *   cron = {"time" = 60}
 * )
 */
class SendPaddleRegistrationEmailWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The customer manager.
   *
   * @var \Drupal\paddle_integration\PaddleCustomerManager
   */
  protected $customerManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Constructs a new SendPaddleRegistrationEmailWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\paddle_integration\PaddleCustomerManager $customer_manager
   *   The customer manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    PaddleCustomerManager $customer_manager,
    LoggerInterface $logger,
    MailManagerInterface $mail_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->customerManager = $customer_manager;
    $this->logger = $logger;
    $this->mailManager = $mail_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('paddle_integration.customer_manager'),
      $container->get('logger.factory')->get('paddle_integration'),
      $container->get('plugin.manager.mail')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Validate data structure.
    if (!isset($data['purchase_id']) || !isset($data['email']) || !isset($data['token'])) {
      $this->logger->error('Invalid queue item data for registration email.');
      return;
    }

    $purchase_id = $data['purchase_id'];
    $email = $data['email'];
    $token = $data['token'];

    try {
      // Generate registration URL.
      // Use Url::fromRoute() which properly handles base URLs.
      $url = \Drupal\Core\Url::fromRoute('paddle_integration.register', ['token' => $token], ['absolute' => TRUE]);
      $registration_url = $url->toString();

      // Prepare email parameters.
      $params = [
        'registration_url' => $registration_url,
        'email' => $email,
        'token' => $token,
      ];

      // Send email via Drupal mail system (which will use Brevo).
      $result = $this->mailManager->mail(
        'paddle_integration',
        'registration',
        $email,
        \Drupal::languageManager()->getDefaultLanguage()->getId(),
        $params,
        NULL,
        TRUE
      );

      if ($result['result']) {
        // Update purchase record with email sent timestamp.
        $this->customerManager->markEmailSent($purchase_id);

        $this->logger->info('Registration email sent to @email for purchase @id', [
          '@email' => $email,
          '@id' => $purchase_id,
        ]);
      }
      else {
        $this->logger->error('Failed to send registration email to @email', [
          '@email' => $email,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Exception while sending registration email: @message', [
        '@message' => $e->getMessage(),
      ]);

      // Re-throw so queue system knows it failed.
      throw $e;
    }
  }

}
