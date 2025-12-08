<?php

namespace Drupal\ai_report_storage\Http;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

/**
 * Decorates the HTTP client to add extended timeout for Anthropic API calls.
 *
 * This ensures that Anthropic API calls use the configured timeout value
 * from ai_provider_anthropic.settings instead of the default 60 seconds.
 */
class AnthropicClientDecorator extends Client {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an AnthropicClientDecorator.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param array $config
   *   Guzzle client configuration.
   */
  public function __construct(ConfigFactoryInterface $config_factory, array $config = []) {
    $this->configFactory = $config_factory;

    // Get the configured timeout from Anthropic provider settings.
    $anthropic_config = $config_factory->get('ai_provider_anthropic.settings');
    $configured_timeout = $anthropic_config->get('timeout') ?? 600;

    // Merge with provided config, prioritizing our timeout.
    $config = array_merge([
      'timeout' => (float) $configured_timeout,
      'connect_timeout' => (float) $configured_timeout,
      'http_errors' => FALSE,
      'verify' => TRUE,
    ], $config);

    // Add middleware to log requests for debugging.
    if (!isset($config['handler'])) {
      $config['handler'] = HandlerStack::create();
    }

    $handler = $config['handler'];
    if ($handler instanceof HandlerStack) {
      $handler->push(Middleware::mapRequest(function (RequestInterface $request) use ($configured_timeout) {
        // Log the request with timeout info if debug function is available.
        if (function_exists('ai_debug_log')) {
          ai_debug_log('HTTP Client Request', [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'timeout' => $configured_timeout,
          ]);
        }
        return $request;
      }));
    }

    parent::__construct($config);
  }

}

