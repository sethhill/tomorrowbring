<?php

namespace Drupal\ai_report_storage\Http;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client with extended timeout for AI API calls.
 */
class ExtendedTimeoutHttpClient implements ClientInterface {

  /**
   * The underlying Guzzle client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * Constructs an ExtendedTimeoutHttpClient.
   */
  public function __construct() {
    $handler = HandlerStack::create();

    $this->client = new Client([
      'handler' => $handler,
      'timeout' => 600,
      'connect_timeout' => 30,
      'http_errors' => false,
      'verify' => TRUE,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function sendRequest(RequestInterface $request): ResponseInterface {
    return $this->client->send($request);
  }

}
