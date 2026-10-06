<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Services\Billing\InvoiceDocumentSector;
use DOMDocument;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class InvoiceXmlValidator
{
    public function validatePurchaseSale(string $xml): void
    {
        $this->validate($xml, InvoiceDocumentSector::PURCHASE_SALE);
    }

    public function validate(string $xml, int $documentSectorCode): void
    {
        $key = InvoiceDocumentSector::schemaConfigKey($documentSectorCode);
        $schemaPath = (string) config(
            'siat.xsd.'.$key,
            resource_path('siat/xsd/'.InvoiceDocumentSector::schemaFilename($documentSectorCode)),
        );

        if (! is_file($schemaPath) || ! is_readable($schemaPath)) {
            throw new RuntimeException('No se encuentra disponible el XSD oficial para el documento sector '.$documentSectorCode.'.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
            $valid = $loaded && $document->schemaValidate($schemaPath);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($valid) {
            return;
        }

        $details = collect($errors)
            ->map(static fn (\LibXMLError $error): string => trim($error->message))
            ->filter()
            ->unique()
            ->take(5)
            ->implode(' ');

        throw ValidationException::withMessages([
            'xml' => 'El XML fiscal no cumple el XSD oficial del documento sector '.$documentSectorCode.'.'
                .($details !== '' ? ' '.$details : ''),
        ]);
    }
}
