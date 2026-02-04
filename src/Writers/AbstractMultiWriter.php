<?php
namespace Einvoicing\Writers;

use Einvoicing\Invoice;

abstract class AbstractMultiWriter extends AbstractWriter{
    /**
     * Export invoice
     * @param  Invoice[] $invoices Invoice instance
     * @return string           Export contents
     */
    abstract public function exportAll(array $invoices): string;
}
