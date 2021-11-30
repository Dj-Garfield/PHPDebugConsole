<?php

/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2021 Brad Kent
 * @version   v3.0
 */

namespace bdk\Debug\Abstraction;

use bdk\Debug\Abstraction\Abstracter;
use bdk\Debug\Abstraction\Abstraction;
use bdk\Debug\Abstraction\AbstractObject;
use ReflectionProperty;

/**
 * Get object property info
 */
class AbstractObjectProperties
{

    protected $abstracter;
    protected $helper;

    private static $basePropInfo = array(
        'attributes' => array(),
        'debugInfoExcluded' => false,   // true if not included in __debugInfo
        'desc' => null,                 // from phpDoc
        'inheritedFrom' => null,        // populated only if inherited
                                        //   not populated if extended/redefined
        'isPromoted' => false,
        'isReadOnly' => false,
        'isStatic' => false,
        'originallyDeclared' => null,   // populated only if originally declared in ancestor
        'overrides' => null,            // previous ancestor where property is defined
                                        //   populated only if we're overriding
        'forceShow' => false,           // initially show the property/value (even if protected or private)
                                        //   if value is an array, expand it
        'type' => null,
        'value' => Abstracter::UNDEFINED,
        'valueFrom' => 'value',         // 'value' | 'debugInfo' | 'debug'
        'visibility' => 'public',       // public, private, protected, magic, magic-read, magic-write, debug
                                        //   may also be an array (ie: ['private', 'magic-read'])
    );

    private $domNodeProps = array(
        'attributes' => 'DOMNamedNodeMap',
        'childNodes' => 'DOMNodeList',
        'firstChild' => 'DOMNode',
        'lastChild' => 'DOMNode',
        'localName' => 'string',
        'namespaceURI' => 'string',
        'nextSibling' => 'DOMNode', // var_dump() doesn't include ¯\_(ツ)_/¯
        'nodeName' => 'string',
        'nodeType' => 'int',
        'nodeValue' => 'string',
        'ownerDocument' => 'DOMDocument',
        'parentNode' => 'DOMNode',
        'prefix' => 'string',
        'previousSibling' => 'DOMNode',
        'textContent' => 'string',
    );

    private $domDocumentProps = array(
        'actualEncoding' => 'string',
        'baseURI' => 'string',
        'config' => 'DOMConfiguration',
        'doctype' => 'DOMDocumentType',
        'documentElement' => 'DOMElement',
        'documentURI' => 'string',
        'encoding' => 'string',
        'formatOutput' => 'bool',
        'implementation' => 'DOMImplementation',
        'preserveWhiteSpace' => 'bool',
        'recover' => 'bool',
        'resolveExternals' => 'bool',
        'standalone' => 'bool',
        'strictErrorChecking' => 'bool',
        'substituteEntities' => 'bool',
        'validateOnParse' => 'bool',
        'version' => 'string',
        'xmlEncoding' => 'string',
        'xmlStandalone' => 'bool',
        'xmlVersion' => 'string',
    );

    private $domElementProps = array(
        'schemaTypeInfo' => 'bool',
        'tagName' => 'string',
    );

    private $magicPhpDocTags = array(
        'property' => 'magic',
        'property-read' => 'magic-read',
        'property-write' => 'magic-write',
    );

    /**
     * Constructor
     *
     * @param Abstracter           $abstracter abstracter instance
     * @param AbstractObjectHelper $helper     helper class
     */
    public function __construct(Abstracter $abstracter, AbstractObjectHelper $helper)
    {
        $this->abstracter = $abstracter;
        $this->helper = $helper;
    }

    /**
     * Add property info/values to abstraction
     *
     * @param Abstraction $abs Object Abstraction instance
     *
     * @return void
     */
    public function add(Abstraction $abs)
    {
        if ($abs['isTraverseOnly']) {
            return;
        }
        $this->abs = $abs;
        $this->addViaReflection($abs);
        $this->addViaPhpDoc($abs); // magic properties documented via phpDoc
        $obj = $abs->getSubject();
        if (\is_object($obj)) {
            $this->addDom($abs);
            $this->addDebug($abs); // use __debugInfo() values if useDebugInfo' && method exists
            if ($abs['className'] === 'Closure') {
                $this->addClosure($abs);
            }
        }
        $this->crate($abs);
    }

    /**
     * Build property info buy passing values
     *
     * @param array $values values to apply
     *
     * @return array
     */
    public static function buildPropValues($values = array())
    {
        return \array_merge(static::$basePropInfo, $values);
    }

    /**
     * Add file & line debug properties for Closure
     *
     * @param Abstraction $abs Object Abstraction instance
     *
     * @return void
     */
    private function addClosure(Abstraction $abs)
    {
        $obj = $abs->getSubject();
        $ref = new \ReflectionFunction($obj);
        $abs['properties']['debug.file'] = static::buildPropValues(array(
            'type' => Abstracter::TYPE_STRING,
            'value' => $ref->getFileName(),
            'valueFrom' => 'debug',
            'visibility' => 'debug',
        ));
        $abs['properties']['debug.line'] = static::buildPropValues(array(
            'type' => Abstracter::TYPE_INT,
            'value' => $ref->getStartLine(),
            'valueFrom' => 'debug',
            'visibility' => 'debug',
        ));
    }

    /**
     * Add/Update properties with info from __debugInfo method
     *
     * @param Abstraction $abs Object Abstraction instance
     *
     * @return void
     */
    private function addDebug(Abstraction $abs)
    {
        if (!$abs['collectPropertyValues']) {
            return;
        }
        if (!$abs['viaDebugInfo']) {
            // using __debugInfo is disabled, or object does not have __debugInfo method
            return;
        }
        $obj = $abs->getSubject();
        // temporarily store __debugInfo values in abstraction
        $abs['debugInfo'] = \call_user_func(array($obj, '__debugInfo'));
        $properties = $this->addDebugWalk($abs);
        /*
            What remains in debugInfo are __debugInfo only values
        */
        foreach ($abs['debugInfo'] as $name => $value) {
            $properties[$name] = static::buildPropValues(array(
                'value' => $value,
                'valueFrom' => 'debugInfo',
                'visibility' => 'debug',    // indicates this "property" is exclusive to debugInfo
            ));
        }
        $abs['properties'] = $properties;
        unset($abs['debugInfo']);
    }

    /**
     * Iterate over properties to set value & valueFrom
     *
     * @param Abstraction $abs Object Abstraction instance
     *
     * @return array
     */
    private function addDebugWalk(Abstraction $abs)
    {
        $debugInfo = $abs['debugInfo'];
        $properties = $abs['properties'];
        foreach ($properties as $name => $info) {
            if (\array_key_exists($name, $abs['propertyOverrideValues'])) {
                // we're using override value
                unset($debugInfo[$name]);
                continue;
            }
            if (\array_key_exists($name, $debugInfo)) {
                if ($debugInfo[$name] !== $info['value']) {
                    $properties[$name]['value'] = $debugInfo[$name];
                    $properties[$name]['valueFrom'] = 'debugInfo';
                }
                unset($debugInfo[$name]);
                continue;
            }
            $isPrivateAncestor = \in_array('private', (array) $info['visibility']) && $info['inheritedFrom'];
            if ($isPrivateAncestor) {
                // exempt from isExcluded
                continue;
            }
            $properties[$name]['debugInfoExcluded'] = true;
        }
        $abs['debugInfo'] = $debugInfo;
        return $properties;
    }

    /**
     * Add properties to Dom* abstraction
     *
     * DOM* properties are invisible to reflection
     * https://bugs.php.net/bug.php?id=48527
     *
     * @param Abstraction $abs Object Abstraction instance
     *
     * @return void
     */
    private function addDom(Abstraction $abs)
    {
        $obj = $abs->getSubject();
        if ($abs['properties']) {
            return;
        }
        if (!$this->isDomObj($obj)) {
            return;
        }
        $props = $this->addDomGetProps($obj);
        foreach ($props as $propName => $type) {
            $val = $obj->{$propName};
            if (!$type) {
                // function array dereferencing = php 5.4
                $type = $this->abstracter->getType($val)[0];
            }
            $abs['properties'][$propName] = static::buildPropValues(array(
                'type' => $type,
                'value' => \is_object($val)
                    ? Abstracter::NOT_INSPECTED
                    : $val,
            ));
        }
    }

    /**
     * use print_r to get the property names
     * get_object_vars() doesn't work
     * var_dump may be overridden by xdebug...  and if xdebug v3 unable to disable at runtime
     *
     * @param object $obj DOMXXX instance
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
     */
    private function addDomGetProps($obj)
    {
        $dump = \print_r($obj, true);
        $matches = array();
        \preg_match_all('/^\s+\[(.+?)\] => /m', $dump, $matches);
        $props = \array_fill_keys($matches[1], null);
        if ($obj instanceof \DOMNode) {
            $props = \array_merge($props, $this->domNodeProps);
            if ($obj instanceof \DOMDocument) {
                $props = \array_merge($props, $this->domDocumentProps);
            } elseif ($obj instanceof \DOMElement) {
                $props = \array_merge($props, $this->domElementProps);
            }
        }
        return $props;
    }

    /**
     * "Magic" properties may be defined in a class' doc-block
     * If so... move this information to the properties array
     *
     * @param Abstraction $abs Object Abstraction instance
     *
     * @return void
     *
     * @see http://docs.phpdoc.org/references/phpdoc/tags/property.html
     */
    private function addViaPhpDoc(Abstraction $abs)
    {
        $inheritedFrom = null;
        $haveMagic = \array_intersect_key($abs['phpDoc'], $this->magicPhpDocTags);
        $obj = $abs->getSubject();
        if (!$haveMagic && \method_exists($obj, '__get')) {
            // phpDoc doesn't contain any @property tags
            // we've got __get method:  check if parent classes have @property tags
            $inheritedFrom = $this->addViaPhpDocInherit($abs);
            $haveMagic = $inheritedFrom !== null;
        }
        if (!$haveMagic) {
            return;
        }
        $this->addPhpDocIter($abs, $inheritedFrom);
    }

    /**
     * Inspect inherited classes until we find properties defined in PhpDoc
     *
     * @param Abstraction $abs Object Abstraction instance
     *
     * @return string|null class where found
     */
    private function addViaPhpDocInherit(Abstraction $abs)
    {
        $inheritedFrom = null;
        $reflector = $abs['reflector'];
        while ($reflector = $reflector->getParentClass()) {
            $parsed = $this->helper->getPhpDoc($reflector);
            $tagIntersect = \array_intersect_key($parsed, $this->magicPhpDocTags);
            if (!$tagIntersect) {
                continue;
            }
            // $haveMagic = true;
            $inheritedFrom = $reflector->getName();
            $abs['phpDoc'] = \array_merge(
                $abs['phpDoc'],
                $tagIntersect
            );
            break;
        }
        return $inheritedFrom;
    }

    /**
     * Adds properties (via reflection) to abstraction
     *
     * @param Abstraction $abs Object Abstraction instance
     *
     * @return void
     */
    private function addViaReflection(Abstraction $abs)
    {
        $refObject = $abs['reflector'];
        /*
            We trace our ancestory to learn where properties are inherited from
        */
        while ($refObject) {
            $className = $refObject->getName();
            $properties = $refObject->getProperties();
            while ($properties) {
                $refProperty = \array_shift($properties);
                $name = $refProperty->getName();
                if (isset($abs['properties'][$name])) {
                    // already have info... we're in an ancestor
                    $abs['properties'][$name]['overrides'] = $this->propOverrides(
                        $refProperty,
                        $abs['properties'][$name],
                        $className
                    );
                    $abs['properties'][$name]['originallyDeclared'] = $className;
                    continue;
                }
                $abs['properties'][$name] = $this->buildPropRef($abs, $refProperty);
            }
            $refObject = $refObject->getParentClass();
        }
    }

    /**
     * Iterate over PhpDoc's magic properties & add to abstrction
     *
     * @param Abstraction $abs           Object Abstraction instance
     * @param string|null $inheritedFrom Where the magic properties were found
     *
     * @return void
     */
    private function addPhpDocIter(Abstraction $abs, $inheritedFrom)
    {
        $properties = $abs['properties'];
        $tags = \array_intersect_key($this->magicPhpDocTags, $abs['phpDoc']);
        foreach ($tags as $tag => $vis) {
            foreach ($abs['phpDoc'][$tag] as $phpDocProp) {
                $name = $phpDocProp['name'];
                $properties[$name] = $this->buildPropPhpDoc($abs, $phpDocProp, $inheritedFrom, $vis);
            }
            unset($abs['phpDoc'][$tag]);
        }
        $abs['properties'] = $properties;
    }

    /**
     * Build property info from parsed PhpDoc values
     *
     * @param Abstraction $abs           Object Abstraction instance
     * @param array       $phpDocProp    parsed property docblock tag
     * @param string      $inheritedFrom className
     * @param string      $vis           prop visibility]
     *
     * @return array
     */
    private function buildPropPhpDoc(Abstraction $abs, $phpDocProp, $inheritedFrom, $vis)
    {
        $name = $phpDocProp['name'];
        $existing = isset($abs['properties'][$name])
            ? $abs['properties'][$name]
            : null;
        return \array_merge(
            $existing ?: self::$basePropInfo,
            array(
                'desc' => $phpDocProp['desc'],
                'type' => $this->helper->resolvePhpDocType($phpDocProp['type'], $abs),
                'inheritedFrom' => $inheritedFrom,
                'visibility' => $existing
                    ? array($existing['visibility'], $vis)
                    : $vis,
            )
        );
    }

    /**
     * Build property info via reflection
     *
     * @param Abstraction        $abs         Object Abstraction instance
     * @param ReflectionProperty $refProperty ReflectionProperty instance
     *
     * @return array
     */
    private function buildPropRef(Abstraction $abs, ReflectionProperty $refProperty)
    {
        $obj = $abs->getSubject();
        $isInstance = \is_object($obj);
        $className = $isInstance
            ? \get_class($obj) // prop->class is equiv to getDeclaringClass
            : $obj;
        $refProperty->setAccessible(true); // only accessible via reflection
        // get type and desc from phpdoc
        $phpDoc = $this->helper->getPhpDocVar($refProperty); // phpDocVar
        /*
            getDeclaringClass returns "LAST-declared/overriden"
        */
        $declaringClassName = $refProperty->getDeclaringClass()->getName();
        $propInfo = static::buildPropValues(array(
            'attributes' => $abs['cfgFlags'] & AbstractObject::COLLECT_ATTRIBUTES_PROP
                ? $this->helper->getAttributes($refProperty)
                : array(),
            'desc' => $phpDoc['desc'],
            'inheritedFrom' => $declaringClassName !== $className
                ? $declaringClassName
                : null,
            'isPromoted' =>  PHP_VERSION_ID >= 80000
                ? $refProperty->isPromoted()
                : false,
            'isReadOnly' => PHP_VERSION_ID >= 80100
                ? $refProperty->isReadOnly()
                : false,
            'isStatic' => $refProperty->isStatic(),
            'type' => $this->getPropType($phpDoc['type'], $refProperty),
            'visibility' => $this->helper->getVisibility($refProperty),
        ));
        if ($abs['collectPropertyValues']) {
            $propInfo = $this->getPropValue($propInfo, $abs, $refProperty);
        }
        return $propInfo;
    }

    /**
     * "Crate" property values
     *
     * @param Abstraction $abs Object Abstraction instance
     *
     * @return void
     */
    private function crate(Abstraction $abs)
    {
        $abs['hist'][] = $abs->getSubject();
        $properties = $this->abs['properties'];
        $collectPhpDoc = $abs['cfgFlags'] & AbstractObject::COLLECT_PHPDOC;
        foreach ($properties as $name => $info) {
            $info['value'] = $this->abstracter->crate($info['value'], $abs['debugMethod'], $abs['hist']);
            if (!$collectPhpDoc) {
                $info['desc'] = null;
            }
            $properties[$name] = $info;
        }
        $abs['properties'] = $properties;
    }

    /**
     * Get Property's type
     * Priority given to phpDoc type, followed by declared type (PHP 7.4)
     *
     * @param string             $phpDocType  Type specified in phpDoc block
     * @param ReflectionProperty $refProperty ReflectionProperty instance
     *
     * @return string|null
     */
    private function getPropType($phpDocType, ReflectionProperty $refProperty)
    {
        $type = $this->helper->resolvePhpDocType($phpDocType, $this->abs);
        if ($type !== null) {
            return $type;
        }
        return PHP_VERSION_ID >= 70400
            ? $this->helper->getTypeString($refProperty->getType())
            : null;
    }

    /**
     * Set 'value' and 'valueFrom' values
     *
     * @param array              $propInfo    propInfo array
     * @param Abstraction        $abs         Object Abstraction instance
     * @param ReflectionProperty $refProperty ReflectionProperty
     *
     * @return array updated propInfo
     */
    private function getPropValue($propInfo, Abstraction $abs, ReflectionProperty $refProperty)
    {
        $propName = $refProperty->getName();
        if (\array_key_exists($propName, $abs['propertyOverrideValues'])) {
            $value = $abs['propertyOverrideValues'][$propName];
            $propInfo['value'] = $value;
            if (\is_array($value) && \array_intersect_key($value, static::$basePropInfo)) {
                $propInfo = $value;
            }
            $propInfo['valueFrom'] = 'debug';
            return $propInfo;
        }
        $obj = $abs->getSubject();
        $isInstance = \is_object($obj);
        if ($isInstance) {
            $isInitialized = PHP_VERSION_ID < 70400 || $refProperty->isInitialized($obj);
            $propInfo['value'] = $isInitialized
                ? $refProperty->getValue($obj)
                : Abstracter::UNDEFINED;  // value won't be displayed
        }
        return $propInfo;
    }

    /**
     * Check if a Dom* class  where properties aren't avail to reflection
     *
     * @param object $obj object to check
     *
     * @return bool
     */
    private function isDomObj($obj)
    {
        return $obj instanceof \DOMNode || $obj instanceof \DOMNodeList;
    }

    /**
     * Determine propInfo['overrides'] value
     *
     * This is the classname of previous ancestor where property is defined
     *
     * @param ReflectionProperty $refProperty Reflection Property
     * @param array              $propInfo    Property Info
     * @param string             $className   className of object being inspected
     *
     * @return string|null
     */
    private function propOverrides(ReflectionProperty $refProperty, $propInfo, $className)
    {
        if (
            empty($propInfo['overrides'])
            && empty($propInfo['inheritedFrom'])
            && $refProperty->getDeclaringClass()->getName() === $className
        ) {
            return $className;
        }
        return null;
    }
}
