<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Rss;

/**
 * SimpleXMLElement with CDATA support for RSS descriptions.
 */
class XmlElement extends \SimpleXMLElement
{
    public function addChildWithCdata(string $name, string $value): XmlElement
    {
        /** @var XmlElement $child */
        $child = $this->addChild($name);
        $node = dom_import_simplexml($child);
        $node->appendChild($node->ownerDocument->createCDATASection($value));

        return $child;
    }
}
