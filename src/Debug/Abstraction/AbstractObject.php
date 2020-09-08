<?php

/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2020 Brad Kent
 * @version   v3.0
 */

namespace bdk\Debug\Abstraction;

use bdk\Debug;
use bdk\Debug\Abstraction\Abstracter;
use bdk\Debug\Abstraction\Abstraction;
use bdk\Debug\Abstraction\AbstractObjectMethods;
use bdk\Debug\Abstraction\AbstractObjectProperties;
use bdk\Debug\Utility\PhpDoc;
use ReflectionClass;
use ReflectionObject;
use RuntimeException;

/**
 * Abstracter:  Methods used to abstract objects
 */
class AbstractObject
{

    const COLLECT_CONSTANTS = 1;
    const COLLECT_METHODS = 2;
    const OUTPUT_CONSTANTS = 4;
    const OUTPUT_METHODS = 8;
    const OUTPUT_METHOD_DESC = 16;

	protected $abstracter;
	protected $phpDoc;

    /**
     * Constructor
     *
     * @param Abstracter $abstracter abstracter instance
     * @param PhpDoc     $phpDoc     phpDoc instance
     */
    public function __construct(Abstracter $abstracter, PhpDoc $phpDoc)
    {
        $this->abstracter = $abstracter;
        $this->phpDoc = $phpDoc;
        if ($abstracter->debug->parentInstance) {
            // we only need to subscribe to these events from root channel
            return;
        }
        $this->abstractObjectProperties = new AbstractObjectProperties($abstracter, $phpDoc);
        $abstracter->debug->eventManager->subscribe(Debug::EVENT_OBJ_ABSTRACT_START, array($this, 'onStart'));
        $abstracter->debug->eventManager->subscribe(Debug::EVENT_OBJ_ABSTRACT_END, array($this, 'onEnd'));
        $abstracter->debug->eventManager->addSubscriberInterface(new AbstractObjectMethods($abstracter, $phpDoc));
        $abstracter->debug->eventManager->addSubscriberInterface($this->abstractObjectProperties);
    }

    /**
     * returns information about an object
     *
     * @param object|string $obj    Object (or classname) to inspect
     * @param string        $method Method requesting abstraction
     * @param array         $hist   (@internal) array & object history
     *
     * @return Abstraction
     * @throws RuntimeException
     */
    public function getAbstraction($obj, $method = null, $hist = array())
    {
        $reflector = $this->getReflector($obj);
        $className = $reflector->getName();
        $interfaceNames = $reflector->getInterfaceNames();
        \sort($interfaceNames);
        $abs = new Abstraction(array(
            'className' => $className,
            'constants' => array(),
            'debugMethod' => $method,
            'definition' => array(
                'fileName' => $reflector->getFileName(),
                'startLine' => $reflector->getStartLine(),
                'extensionName' => $reflector->getExtensionName(),
            ),
            'extends' => array(),
            'flags' => $this->getFlags(),
            'implements' => $interfaceNames,
            'isExcluded' => $hist && $this->isExcluded($obj),    // don't exclude if we're debugging directly
            'isRecursion' => \in_array($obj, $hist, true),
            'methods' => array(),   // if !collectMethods, may still get ['__toString']['returnValue']
            'phpDoc' => array(
                'summary' => null,
                'desc' => null,
                // additional tags
            ),
            'properties' => array(),
            'scopeClass' => $this->getScopeClass($hist),
            'stringified' => null,
            'type' => Abstracter::TYPE_OBJECT,
            'traverseValues' => array(),    // populated if method is table && traversable
            'viaDebugInfo' => $this->abstracter->getCfg('useDebugInfo') && $reflector->hasMethod('__debugInfo'),
            // these are temporary values available during abstraction
            'collectPropertyValues' => true,
            'fullyQualifyPhpDocType' => $this->abstracter->getCfg('fullyQualifyPhpDocType'),
            'hist' => $hist,
            'isTraverseOnly' => false,
            'propertyOverrideValues' => array(),
            'reflector' => $reflector,
        ));
        if ($abs['isRecursion']) {
            return $this->absClean($abs);
        }
        $abs->setSubject($obj);
        $abs['isTraverseOnly'] = $this->isTraverseOnly($abs);
        /*
            Debug::EVENT_OBJ_ABSTRACT_START subscriber may
            set isExcluded
            set collectPropertyValues (boolean)
            set flags (int / bitmask)
            set propertyOverrideValues
            set stringified
            set traverseValues
        */
        $this->abstracter->debug->publishBubbleEvent(Debug::EVENT_OBJ_ABSTRACT_START, $abs, $this->abstracter->debug);
        if ($abs['isExcluded']) {
            return $this->absClean($abs);
        }
        $this->addMisc($abs);
        /*
            Debug::EVENT_OBJ_ABSTRACT_END subscriber has free reign to modify abtraction array
        */
        $this->abstracter->debug->publishBubbleEvent(Debug::EVENT_OBJ_ABSTRACT_END, $abs, $this->abstracter->debug);
        return $this->absClean($abs);
    }

    /**
     * Debug::EVENT_OBJ_ABSTRACT_START event subscriber
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return void
     */
    public function onStart(Abstraction $abs)
    {
        $obj = $abs->getSubject();
        if ($obj instanceof \DateTime || $obj instanceof \DateTimeImmutable) {
            $abs['stringified'] = $obj->format(\DateTime::ISO8601);
        } elseif ($obj instanceof \mysqli) {
            \set_error_handler(function ($errno, $errstr) {
                throw new RuntimeException($errstr, $errno);
            }, E_ALL);
            try {
                $obj->stat();
            } catch (RuntimeException $e) {
                /*
                    stat() threw an error, so we'll not collect property values to
                    avoid "Property access is not allowed yet"
                */
                $abs['collectPropertyValues'] = false;
            }
            \restore_error_handler();
        } elseif ($obj instanceof Debug) {
            $abs['propertyOverrideValues']['data'] = Abstracter::NOT_INSPECTED;
        } elseif ($obj instanceof PhpDoc) {
            $abs['propertyOverrideValues']['cache'] = Abstracter::NOT_INSPECTED;
        } elseif ($obj instanceof self) {
            $abs['propertyOverrideValues']['methodCache'] = Abstracter::NOT_INSPECTED;
        }
    }

    /**
     * Debug::EVENT_OBJ_ABSTRACT_END event subscriber
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return void
     */
    public function onEnd(Abstraction $abs)
    {
        $obj = $abs->getSubject();
        if ($obj instanceof \Exception) {
            if (isset($abs['properties']['xdebug_message'])) {
                $abs['properties']['xdebug_message']['debugInfoExcluded'] = true;
            }
        } elseif ($obj instanceof \mysqli && !$abs['collectPropertyValues']) {
            $propsAlwaysAvail = array(
                'client_info','client_version','connect_errno','connect_error','errno','error','stat'
            );
            $reflectionObject = $abs['reflector'];
            foreach ($propsAlwaysAvail as $name) {
                if (!isset($abs['properties'][$name])) {
                    // stat property may be missing in php 7.4??
                    continue;
                }
                $abs['properties'][$name]['value'] = $reflectionObject->getProperty($name)->getValue($obj);
            }
        }
        if (\strpos($abs['className'], "anonymous\0") !== false) {
            $this->cleanAnonymous($abs);
        }
    }

    /**
     * Sort things and remove temporary values
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return Abstraction
     */
    private function absClean(Abstraction $abs)
    {
        $keysTemp = array(
            'collectPropertyValues',
            'fullyQualifyPhpDocType',
            'hist',
            'isTraverseOnly',
            'propertyOverrideValues',
            'reflector',
        );
        $values = \array_diff_key($abs->getValues(), \array_flip($keysTemp));
        if (!$abs['isRecursion'] && !$abs['isExcluded']) {
            $this->sort($values['properties']);
            $this->sort($values['methods']);
        }
        return $abs
            ->setSubject(null)
            ->setValues($values);
    }

    /**
     * Get object's constants
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return void
     */
    private function addConstants(Abstraction $abs)
    {
        if (!($abs['flags'] & self::COLLECT_CONSTANTS)) {
            return;
        }
        $reflector = $abs['reflector'];
        $constants = $reflector->getConstants();
        while ($reflector = $reflector->getParentClass()) {
            $constants = \array_merge($reflector->getConstants(), $constants);
        }
        if ($this->abstracter->getCfg('objectSort') === 'name') {
            \ksort($constants);
        }
        $abs['constants'] = $constants;
    }

    /**
     * Populate constants, extends, phpDoc, & traverseValues
     *
     * methods added separately via AbstractObjectMethods::onAbstractEnd
     * properties added separately via AbstractObjectProperties::onAbstractEnd
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return void
     */
    private function addMisc(Abstraction $abs)
    {
        $reflector = $abs['reflector'];
        $abs['phpDoc'] = $this->phpDoc->getParsed($reflector);
        if ($abs['isTraverseOnly']) {
            $this->addTraverseValues($abs);
            return;
        }
        $this->addConstants($abs);
        while ($reflector = $reflector->getParentClass()) {
            $abs['extends'][] = $reflector->getName();
        }
    }

    /**
     * Populate rows or columns (traverseValues) if we're outputing as a table
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return void
     */
    private function addTraverseValues(Abstraction $abs)
    {
        if ($abs['traverseValues']) {
            return;
        }
        $obj = $abs->getSubject();
        $abs['hist'][] = $obj;
        foreach ($obj as $k => $v) {
            $absInfo = $this->abstracter->needsAbstraction($v);
            $abs['traverseValues'][$k] = $absInfo
                ? $this->abstracter->getAbstraction($v, $abs['debugMethod'], $absInfo, $abs['hist'])
                : $v;
        }
    }

    /**
     * Make anonymous class more user friendly
     *
     *  * adjust classname
     *  * add file & line debug properties
     *
     * Where is this anonymous class notation documented?
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return void
     */
    private function cleanAnonymous(Abstraction $abs)
    {
        $regex = '/[a-zA-Z_\x7f-\xff][\\\\a-zA-Z0-9_\x7f-\xff]*+@anonymous\x00(.*?\.php)(?:0x?|:([0-9]++)\$)[0-9a-fA-F]++/';
        $matches = array();
        \preg_match($regex, $abs['className'], $matches);
        if (!$matches) {
            return;
        }
        $abs['className'] = \class_exists($matches[0], false)
            ? (\get_parent_class($matches[0]) ?: \key(\class_implements($matches[0])) ?: 'class') . '@anonymous'
            : $matches[0];
        $properties = $abs['properties'];
        $properties['debug.file'] = $this->abstractObjectProperties->buildPropInfo(array(
            'type' => Abstracter::TYPE_STRING,
            'value' => $matches[1],
            'valueFrom' => 'debug',
            'visibility' => 'debug',
        ));
        if ($matches[2]) {
            $properties['debug.line'] = $this->abstractObjectProperties->buildPropInfo(array(
                'type' => Abstracter::TYPE_INT,
                'value' => (int) $matches[2],
                'valueFrom' => 'debug',
                'visibility' => 'debug',
            ));
        }
        $abs['properties'] = $properties;
    }

    /**
     * Get configuration flags
     *
     * @return int bitmask
     */
    private function getFlags()
    {
        $flags = array(
            'collectConstants' => self::COLLECT_CONSTANTS,
            'collectMethods' => self::COLLECT_METHODS,
            'outputConstants' => self::OUTPUT_CONSTANTS,
            'outputMethodDesc' => self::OUTPUT_METHOD_DESC,
            'outputMethods' => self::OUTPUT_METHODS,
        );
        $config = $this->abstracter->getCfg();
        $config = \array_intersect_key($flags, \array_filter($config));
        return \array_reduce($config, function ($carry, $val) {
            return $carry | $val;
        }, 0);
    }

    /**
     * Get ReflectionObject or ReflectionClass instance
     *
     * @param object|string $obj Object (or classname)
     *
     * @return ReflectionObject|ReflectionClass
     * @throws RuntimeException
     */
    private function getReflector($obj)
    {
        if (\is_object($obj)) {
            return new ReflectionObject($obj);
        }
        if (\is_string($obj) && (\class_exists($obj) || \interface_exists($obj))) {
            return new ReflectionClass($obj);
        }
        throw new RuntimeException(__METHOD__ . ' expects and object, className, or interfaceName');
    }

    /**
     * for nested objects (ie object is a property of an object), returns the parent class
     *
     * @param array $hist Array & object history
     *
     * @return null|string
     */
    private function getScopeClass(&$hist)
    {
        $className = null;
        for ($i = \count($hist) - 1; $i >= 0; $i--) {
            if (\is_object($hist[$i])) {
                $className = \get_class($hist[$i]);
                break;
            }
        }
        if ($i < 0) {
            $backtrace = \version_compare(PHP_VERSION, '5.4.0', '>=')
                ? \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
                : \debug_backtrace(false);   // don't provide object
            foreach ($backtrace as $i => $frame) {
                if (!isset($frame['class']) || \strpos($frame['class'], __NAMESPACE__) !== 0) {
                    break;
                }
            }
            $className = isset($backtrace[$i]['class'])
                ? $backtrace[$i]['class']
                : null;
        }
        return $className;
    }

    /**
     * Is the passed object excluded from debugging?
     *
     * @param object|string $obj object (or classname) to test
     *
     * @return bool
     */
    private function isExcluded($obj)
    {
        $classname = \is_object($obj)
            ? \get_class($obj)
            : $obj;
        if (\array_intersect(array('*', $classname), $this->abstracter->getCfg('objectsExclude'))) {
            return true;
        }
        // now test "instanceof"
        foreach ($this->abstracter->getCfg('objectsExclude') as $exclude) {
            if (\is_subclass_of($obj, $exclude)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Test if only need to populate traverseValues
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return bool
     */
    private function isTraverseOnly(Abstraction $abs)
    {
        if ($abs['debugMethod'] === 'table' && \count($abs['hist']) < 2) {
            $obj = $abs->getSubject();
            if ($obj instanceof \Traversable) {
                $abs['flags'] &= ~self::COLLECT_METHODS;  // set collect methods to "false"
                return true;
            }
        }
        return false;
    }

    /**
     * Sorts property/method array by visibility or name
     *
     * @param array $array array to sort
     *
     * @return void
     */
    protected function sort(&$array)
    {
        if (!$array) {
            return;
        }
        $sort = $this->abstracter->getCfg('objectSort');
        if ($sort === 'name') {
            // rather than a simple key sort, use array_multisort so that __construct is always first
            $sortData = array();
            foreach (\array_keys($array) as $name) {
                $sortData[$name] = $name === '__construct'
                    ? '0'
                    : $name;
            }
            \array_multisort($sortData, $array);
        } elseif ($sort === 'visibility') {
            $sortVisOrder = array('public', 'protected', 'private', 'magic', 'magic-read', 'magic-write', 'debug');
            $sortData = array();
            foreach ($array as $name => $info) {
                $sortData['name'][$name] = $name === '__construct'
                    ? '0'     // always place __construct at the top
                    : $name;
                $vis = \is_array($info['visibility'])
                    ? $info['visibility'][0]
                    : $info['visibility'];
                $sortData['vis'][$name] = \array_search($vis, $sortVisOrder);
            }
            \array_multisort($sortData['vis'], $sortData['name'], $array);
        }
    }
}
