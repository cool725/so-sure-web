<?php

namespace AppBundle\Service\XmlAdapter;

use SimpleXMLElement;
use DOMDocument;
use Symfony\Component\Config\Util\Exception\XmlParsingException;

class ArrayToXml
{
    /**
     * @param array            $data
     * @param string           $rootNodeName - what you want the root node to be.
     * @param SimpleXMLElement $xml          - should only be used recursively
     * @return string XML
     */
    public static function toXml($data, $rootNodeName = 'data', &$xml = null)
    {
        // turn off compatibility mode as simple xml throws a wobbly if you don't.
        if (ini_get('zend.ze1_compatibility_mode') == 1) {
            ini_set('zend.ze1_compatibility_mode', 0);
        }
        if (null === $xml) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string(
                stripslashes('<?xml version="1.0" encoding="utf-8"?>
                        <root xmlns="https://wearesosure.com" version="1.0"></root>'),
                "SimpleXMLElement",
                LIBXML_NOWARNING
            );
        }

        // loop through the data passed in.
        foreach ($data as $key => $value) {
            // no numeric keys in our xml please!
            $numeric = false;
            if (is_numeric($key)) {
                $numeric = 1;
                $key = $rootNodeName;
            }

            // delete any char not allowed in XML element names
            $key = preg_replace('/[^a-z0-9\-\_\.\:]/i', '', $key);

            //check to see if there should be an attribute added (expecting to see _id_)
            $attrs = false;

            //if there are attributes in the array (denoted by attr_**) then add as XML attributes
            if (is_array($value)) {
                foreach ($value as $i => $v) {
                    $attr_start = mb_stripos($i, 'attr_');
                    if ($attr_start === 0) {
                        $attrs[mb_substr($i, 5)] = $v;
                        unset($value[$i]);
                    }
                }
            }
            // if there is another array found recursively call this function
            if (is_array($value)) {
                if (ArrayToXML::isAssoc($value) || $numeric) {
                    // older SimpleXMLElement Libraries do not have the addChild Method
                    if (method_exists('SimpleXMLElement', 'addChild')) {
                        $node = $xml->addChild($key, null, 'phone');
                        if ($attrs) {
                            foreach ($attrs as $keys => $attribute) {
                                $node->addAttribute($keys, $attribute);
                            }
                        }
                    }
                } else {
                    $node =$xml;
                }

                // recrusive call.
                if ($numeric) {
                    $key = 'anon';
                }
                ArrayToXML::toXml($value, $key, $node);
            } else {
                // older SimplXMLElement Libraries do not have the addChild Method
                if (method_exists('SimpleXMLElement', 'addChild')) {
                    $childnode = $xml->addChild($key, $value, 'phone');
                    if ($attrs) {
                        foreach ($attrs as $keyx => $attribute) {
                            $childnode->addAttribute($keyx, $attribute);
                        }
                    }
                }
            }
        }

        // if you want the XML to be formatted, use the below instead to return the XML
        try {
            $doc = new DOMDocument('1.0');
            $doc->preserveWhiteSpace = false;
            $doc->loadXML(ArrayToXML::fixCDATA($xml->asXML()));
            $doc->formatOutput = true;
            $xmlString = $doc->saveXML();
        } catch (XmlParsingException $e) {
            return $e->getMessage();
        }
        return $xmlString;
    }

    public static function fixCDATA($string)
    {
        //fix CDATA tags
        $find[]     = '&lt;![CDATA[';
        $replace[] = '<![CDATA[';
        $find[]     = ']]&gt;';
        $replace[] = ']]>';

        $string = str_ireplace($find, $replace, $string);
        return $string;
    }

    /**
     * Convert an XML document to a multi dimensional array
     * Pass in an XML document (or SimpleXMLElement object) and this recrusively loops
     *
     * @param string $xml - XML document - can optionally be a SimpleXMLElement object
     * @return array ARRAY
     */
    public static function toArray($xml)
    {
        if (is_string($xml)) {
            $xml = new SimpleXMLElement($xml);
        }
        $children = $xml->children();
        if (!$children) {
            return (string) $xml;
        }
        $arr = array();
        foreach ($children as $key => $node) {
            $node = ArrayToXML::toArray($node);

            // support for 'anon' non-associative arrays
            if ($key === 'anon') {
                $key = count($arr);
            }

            // if the node is already set, put it into an array
            if (isset($arr[$key])) {
                if (!is_array($arr[$key]) || $arr[$key][0] == null) {
                    $arr[$key] = array($arr[$key]);
                }
                $arr[$key][] = $node;
            } else {
                $arr[$key] = $node;
            }
        }
        return $arr;
    }

    public static function isAssoc($array)
    {
        return (is_array($array) && 0 !== count(array_diff_key($array, array_keys(array_keys($array)))));
    }
}
