<?php

namespace Drupal\ai_report_storage\Http;

use GuzzleHttp\Client;

/**
 * Guzzle HTTP client with extended timeout for AI API calls.
 *
 * This extends Guzzle's Client directly to ensure compatibility with
 * the OpenAI SDK's stream handler detection.
 */
class ExtendedTimeoutHttpClient extends Client {

  /**
   * Constructs an ExtendedTimeoutHttpClient.
   */
  public function __construct() {
    parent::__construct([
      'timeout' => 600,
      'connect_timeout' => 600,
      'http_errors' => false,
      'verify' => TRUE,
    ]);
  }

}
