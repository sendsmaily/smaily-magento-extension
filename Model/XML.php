<?php

namespace Smaily\SmailyForMagento\Model;

class XML extends \SimpleXMLElement
{
    /**
     * Add child node with CDATA wrapped value.
     *
     * @param string $name
     * @param string|null $value
     * @param string|null $namespace
     * @access public
     * @return \SimpleXMLElement|null
     */
    public function addChildWithCDATA($name, $value = null, $namespace = null)
    {
        $child = $this->addChild($name, null, $namespace);

        if ($child !== null) {
            $node = dom_import_simplexml($child);
            $document = $node->ownerDocument;
            $node->appendChild($document->createCDATASection($value));
        }

        return $child;
    }
}
