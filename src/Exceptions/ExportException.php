<?php
namespace Einvoicing\Exceptions;

use RuntimeException;

/**
 * Thrown when a document cannot be exported, because the invoice holds a value
 * no valid document can express.
 */
class ExportException extends RuntimeException {
}
