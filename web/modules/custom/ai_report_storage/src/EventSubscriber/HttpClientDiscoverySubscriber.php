<?php

namespace Drupal\ai_report_storage\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\Client as GuzzleClient;
use Http\Factory\Guzzle\StreamFactory;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\ResponseFactory;
use Http\Factory\Guzzle\UriFactory;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Configures HTTP client discovery for Anthropic SDK with extended timeout.
 *
 * The Anthropic SDK uses PSR-18 HTTP client discovery, which bypasses
 * Drupal's HTTP client configuration. This subscriber ensures that
 * discovered clients have the proper timeout configuration.
 */
class HttpClientDiscoverySubscriber implements EventSubscriberInterface {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Whether the client has been registered.
   *
   * @var bool
   */
  protected static $registered = FALSE;

  /**
   * Constructs a new HttpClientDiscoverySubscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Run very early in the request to ensure HTTP client is configured
    // before any Anthropic API calls.
    $events[KernelEvents::REQUEST][] = ['onKernelRequest', 1000];
    return $events;
  }

  /**
   * Configures HTTP client discovery with extended timeout.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onKernelRequest(RequestEvent $event) {
    // Only register once per request.
    if (self::$registered) {
      return;
    }

    // Get the configured timeout.
    $anthropic_config = $this->configFactory->get('ai_provider_anthropic.settings');
    $ai_report_config = $this->configFactory->get('ai_report_storage.settings');

    // Use the timeout from ai_report_storage if available, fallback to anthropic config.
    $timeout = $ai_report_config->get('timeout') ?? $anthropic_config->get('timeout') ?? 600;

    try {
      // Create a Guzzle client with extended timeout.
      $client = new GuzzleClient([
        'timeout' => (float) $timeout,
        'connect_timeout' => (float) $timeout,
        'http_errors' => FALSE,
        'verify' => TRUE,
        'curl' => [
          CURLOPT_TIMEOUT => (int) $timeout,
          CURLOPT_CONNECTTIMEOUT => (int) $timeout,
          CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
          CURLOPT_CONNECTTIMEOUT_MS => (int) ($timeout * 1000),
        ],
      ]);

      // Register this client with PSR-18 discovery.
      // This ensures the Anthropic SDK will find and use this client.
      HttpClientDiscovery::prependStrategy(function () use ($client) {
        return $client;
      });

      self::$registered = TRUE;

      // Log the registration if debug function is available.
      if (function_exists('ai_debug_log')) {
        ai_debug_log('HTTP Client Discovery Configured', [
          'timeout' => $timeout,
          'registered' => TRUE,
        ]);
      }
    }
    catch (\Exception $e) {
      // Log error but don't throw - this is not critical enough to break the request.
      \Drupal::logger('ai_report_storage')->error('Failed to configure HTTP client discovery: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

}

