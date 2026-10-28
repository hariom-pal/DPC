<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Support;

use App\Integrations\Uidai\UidaiProtocolException;
use DOMAttr;
use DOMDocument;
use DOMElement;
use Throwable;

/**
 * Loads a UIDAI response document safely.
 *
 * The XML arrives through a third party, so: network access off (LIBXML_NONET)
 * and entity substitution OFF — LIBXML_NOENT is deliberately not passed. (The
 * Welfare `OtpResponseParser` this was ported from passes NOENT, which
 * substitutes entities; that is not carried over.) A document carrying a DTD is
 * refused outright: no UIDAI response has one.
 */
final class SafeXml
{
    /** @throws UidaiProtocolException */
    public static function root(string $xml, string $expectedRoot): DOMElement
    {
        if (trim($xml) === '') {
            throw new UidaiProtocolException('UIDAI returned an empty response.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false || $document->doctype !== null || ! $document->documentElement instanceof DOMElement) {
            throw new UidaiProtocolException('The UIDAI response could not be parsed as XML.');
        }

        $root = $document->documentElement;

        if ($root->localName !== $expectedRoot) {
            throw new UidaiProtocolException(sprintf('Expected <%s> from UIDAI; got <%s>.', $expectedRoot, $root->localName));
        }

        return $root;
    }

    /** Null when absent or empty — the two mean the same on every attribute read here. */
    public static function attribute(DOMElement $element, string $name): ?string
    {
        $attribute = $element->getAttributeNode($name);

        if (! $attribute instanceof DOMAttr || $attribute->value === '') {
            return null;
        }

        return $attribute->value;
    }
}
