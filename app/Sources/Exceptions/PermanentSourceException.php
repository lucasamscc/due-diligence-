<?php

namespace App\Sources\Exceptions;

/** 4xx, chave ausente, dataset nunca ingerido. Falha na hora: retry só gastaria cota. */
class PermanentSourceException extends SourceException {}
