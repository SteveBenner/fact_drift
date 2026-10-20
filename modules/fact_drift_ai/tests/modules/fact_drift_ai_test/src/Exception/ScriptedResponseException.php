<?php

namespace Drupal\fact_drift_ai_test\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * An error that carries the HTTP response, like the OpenAI client's.
 */
class ScriptedResponseException extends \RuntimeException {

  /**
   * Constructs the exception.
   *
   * @param string $message
   *   The message.
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response the provider's server sent.
   */
  public function __construct(string $message, public readonly ResponseInterface $response) {
    parent::__construct($message, $response->getStatusCode());
  }

}
