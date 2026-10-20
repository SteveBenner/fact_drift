<?php

namespace Drupal\fact_drift_ai_test\Exception;

/**
 * A transport failure that wraps the Guzzle exception, like the OpenAI client.
 */
class ScriptedTransportException extends \RuntimeException {
}
