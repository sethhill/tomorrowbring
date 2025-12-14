<?php

namespace Drupal\paddle_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\paddle_integration\PaddleApiClient;
use Drupal\paddle_integration\PaddleWebhookHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

/**
 * Controller for Paddle webhook endpoint.
 */
class WebhookController extends ControllerBase {

  /**
   * The Paddle API client.
   *
   * @var \Drupal\paddle_integration\PaddleApiClient
   */
  protected $apiClient;

  /**
   * The webhook handler.
   *
   * @var \Drupal\paddle_integration\PaddleWebhookHandler
   */
  protected $webhookHandler;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a WebhookController.
   *
   * @param \Drupal\paddle_integration\PaddleApiClient $api_client
   *   The Paddle API client.
   * @param \Drupal\paddle_integration\PaddleWebhookHandler $webhook_handler
   *   The webhook handler.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    PaddleApiClient $api_client,
    PaddleWebhookHandler $webhook_handler,
    LoggerInterface $logger
  ) {
    $this->apiClient = $api_client;
    $this->webhookHandler = $webhook_handler;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('paddle_integration.api_client'),
      $container->get('paddle_integration.webhook_handler'),
      $container->get('logger.channel.paddle_integration')
    );
  }

  /**
   * Receive and process Paddle webhook.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function receive(Request $request) {
    // Get raw POST body.
    $payload = $request->getContent();

    // Get Paddle signature from headers.
    $signature = $request->headers->get('Paddle-Signature');

    // Validate signature.
    if (!$this->apiClient->validateWebhookSignature($payload, $signature)) {
      $this->logger->error('Webhook signature validation failed');
      return new Response('Unauthorized', 401);
    }

    // Parse JSON payload.
    $data = json_decode($payload, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->logger->error('Failed to parse webhook JSON: @error', [
        '@error' => json_last_error_msg(),
      ]);
      return new Response('Bad Request', 400);
    }

    // Log the full webhook payload for debugging.
    $this->logger->info('Webhook payload: @payload', [
      '@payload' => json_encode($data, JSON_PRETTY_PRINT),
    ]);

    // Process webhook.
    try {
      $result = $this->webhookHandler->processWebhook($data);

      if ($result) {
        $this->logger->info('Webhook processed successfully');
        return new Response('OK', 200);
      }
      else {
        $this->logger->error('Webhook processing failed');
        // Still return 200 to prevent Paddle from retrying.
        return new Response('OK', 200);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook processing exception: @message', [
        '@message' => $e->getMessage(),
      ]);
      // Still return 200 to prevent infinite retries.
      return new Response('OK', 200);
    }
  }

}
