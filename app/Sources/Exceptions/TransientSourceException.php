<?php

namespace App\Sources\Exceptions;

/** Timeout, conexão recusada, 5xx, 429. Conta para o circuit breaker e é reprocessada com backoff. */
class TransientSourceException extends SourceException {}
