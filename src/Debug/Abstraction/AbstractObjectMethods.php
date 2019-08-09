<?php
/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2019 Brad Kent
 * @version   v3,0
 */

namespace bdk\Debug\Abstraction;

use bdk\Debug\PhpDoc;
use bdk\Debug\Abstraction\Abstracter;
use bdk\Debug\Abstraction\Abstraction;
use bdk\PubSub\SubscriberInterface;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Get object method info
 */
class AbstractObjectMethods implements SubscriberInterface
{

    protected $abstracter;
    static private $methodCache = array();
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
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriptions()
    {
        return array(
            'debug.objAbstractEnd' => array('onAbstractEnd', PHP_INT_MAX),
        );
    }

    /**
     * debug.objAbstracctStart listener
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return void
     */
    public function onAbstractEnd(Abstraction $abs)
    {
        if ($abs['isTraverseOnly']) {
            return;
        }
        if ($abs['collectMethods']) {
            $this->addMethods($abs);
        } else {
            $this->addMethodsMin($abs);
        }
    }

    /**
     * Adds methods to abstraction
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return void
     */
    private function addMethods(Abstraction $abs)
    {
        $obj = $abs->getSubject();
        if ($this->abstracter->getCfg('cacheMethods') && isset(static::$methodCache[$abs['className']])) {
            $abs['methods'] = static::$methodCache[$abs['className']];
        } else {
            $methodArray = array();
            $methods = $abs['reflector']->getMethods();
            $interfaceMethods = array(
                'ArrayAccess' => array('offsetExists','offsetGet','offsetSet','offsetUnset'),
                'Countable' => array('count'),
                'Iterator' => array('current','key','next','rewind','void'),
                'IteratorAggregate' => array('getIterator'),
                // 'Throwable' => array('getMessage','getCode','getFile','getLine','getTrace','getTraceAsString','getPrevious','__toString'),
            );
            $interfacesHide = \array_intersect($abs['implements'], \array_keys($interfaceMethods));
            foreach ($methods as $reflectionMethod) {
                $info = $this->methodInfo($obj, $reflectionMethod);
                $methodName = $reflectionMethod->getName();
                if ($info['visibility'] === 'private' && $info['inheritedFrom']) {
                    /*
                        getMethods() returns parent's private methods (#reasons)..  we'll skip it
                    */
                    continue;
                }
                foreach ($interfacesHide as $interface) {
                    if (\in_array($methodName, $interfaceMethods[$interface])) {
                        // this method implements this interface
                        $info['implements'] = $interface;
                        break;
                    }
                }
                $methodArray[$methodName] = $info;
            }
            $abs['methods'] = $methodArray;
            $this->addMethodsPhpDoc($abs);
            static::$methodCache[$abs['className']] = $abs['methods'];
        }
        if (isset($abs['methods']['__toString'])) {
            $abs['methods']['__toString']['returnValue'] = $obj->__toString();
        }
        return;
    }

    /**
     * Add minimal method information to abstraction
     *
     * @param Abstraction $abs Abstraction event object
     *
     * @return void
     */
    private function addMethodsMin(Abstraction $abs)
    {
        $obj = $abs->getSubject();
        if (\method_exists($obj, '__toString')) {
            $abs['methods']['__toString'] = array(
                'returnValue' => $obj->__toString(),
                'visibility' => 'public',
            );
        }
        if (\method_exists($obj, '__get')) {
            $abs['methods']['__get'] = array('visibility' => 'public');
        }
        if (\method_exists($obj, '__set')) {
            $abs['methods']['__set'] = array('visibility' => 'public');
        }
        return;
    }

    /**
     * "Magic" methods may be defined in a class' doc-block
     * If so... move this information to method info
     *
     * @param Abstraction $abs Abstraction event object
     *
     * @return void
     *
     * @see http://docs.phpdoc.org/references/phpdoc/tags/method.html
     */
    private function addMethodsPhpDoc(Abstraction $abs)
    {
        $inheritedFrom = null;
        if (empty($abs['phpDoc']['method'])) {
            // phpDoc doesn't contain any @method tags,
            if (\array_intersect_key($abs['methods'], \array_flip(array('__call', '__callStatic')))) {
                // we've got __call and/or __callStatic method:  check if parent classes have @method tags
                $reflector = $abs['reflector'];
                while ($reflector = $reflector->getParentClass()) {
                    $parsed = $this->phpDoc->getParsed($reflector);
                    if (isset($parsed['method'])) {
                        $inheritedFrom = $reflector->getName();
                        $abs['phpDoc']['method'] = $parsed['method'];
                        break;
                    }
                }
            }
            if (empty($abs['phpDoc']['method'])) {
                // still empty
                return;
            }
        }
        foreach ($abs['phpDoc']['method'] as $phpDocMethod) {
            $className = $inheritedFrom ? $inheritedFrom : $abs['className'];
            $abs['methods'][$phpDocMethod['name']] = array(
                'implements' => null,
                'inheritedFrom' => $inheritedFrom,
                'isAbstract' => false,
                'isDeprecated' => false,
                'isFinal' => false,
                'isStatic' => $phpDocMethod['static'],
                'params' => \array_map(function ($param) use ($className) {
                    return array(
                        'defaultValue' => $this->phpDocParamValue($param, $className),
                        'desc' => null,
                        'name' => $param['name'],
                        'optional' => false,
                        'type' => $param['type'],
                    );
                }, $phpDocMethod['param']),
                'phpDoc' => array(
                    'summary' => $phpDocMethod['desc'],
                    'desc' => null,
                ),
                'return' => array(
                    'type' => $phpDocMethod['type'],
                    'desc' => null,
                ),
                'visibility' => 'magic',
            );
        }
        unset($abs['phpDoc']['method']);
        return;
    }

    /**
     * Get parameter details
     *
     * returns array of
     *     [
     *         'defaultValue'   value or Abstracter::UNDEFINED
     *         'desc'           description (from phpDoc)
     *         'isOptional'
     *         'name'           name
     *         'type'           type hint
     *     ]
     *
     * @param ReflectionMethod $reflectionMethod method object
     * @param array            $phpDoc           method's parsed phpDoc comment
     *
     * @return array
     */
    private function getParams(ReflectionMethod $reflectionMethod, $phpDoc = array())
    {
        $paramArray = array();
        $params = $reflectionMethod->getParameters();
        foreach ($params as $i => $reflectionParameter) {
            $phpDocParam = isset($phpDoc['param'][$i])
                ? $phpDoc['param'][$i]
                : array();
            $paramArray[] = array(
                'defaultValue' => $this->getParamDefaultVal($reflectionParameter),
                'desc' => isset($phpDocParam['desc'])
                    ? $phpDocParam['desc']
                    : null,
                'isOptional' => $reflectionParameter->isOptional(),
                'name' => $this->getParamName($reflectionParameter, $phpDocParam),
                'type' => $this->getParamTypeHint($reflectionParameter, $phpDocParam),
            );
        }
        /*
            Iterate over params only defined via phpDoc
        */
        $phpDocCount = isset($phpDoc['param'])
            ? \count($phpDoc['param'])
            : 0;
        for ($i = \count($params); $i < $phpDocCount; $i++) {
            $phpDocParam = $phpDoc['param'][$i];
            $name = '$'.$phpDocParam['name'];
            if (\substr($name, -4) === ',...') {
                $name = '...'.\substr($name, 0, -4);
            }
            $paramArray[] = array(
                'defaultValue' => $this->phpDocParamValue($phpDocParam),
                'desc' => $phpDocParam['desc'],
                'isOptional' => true,
                'name' => $name,
                'type' => $phpDocParam['type'],
            );
        }
        return $paramArray;
    }

    /**
     * Get param's default value
     *
     * @param ReflectionParameter $reflectionParameter reflectionParameter
     *
     * @return mixed
     */
    private function getParamDefaultVal(ReflectionParameter $reflectionParameter)
    {
        $defaultValue = Abstracter::UNDEFINED;
        if ($reflectionParameter->isDefaultValueAvailable()) {
            // suppressing following to avoid "Use of undefined constant STDERR" type notice
            $defaultValue = @$reflectionParameter->getDefaultValue();
            if (\version_compare(PHP_VERSION, '5.4.6', '>=') && $reflectionParameter->isDefaultValueConstant()) {
                /*
                    getDefaultValueConstantName() :
                        php may return something like self::CONSTANT_NAME
                        hhvm will return WhateverTheClassNameIs::CONSTANT_NAME
                */
                $defaultValue = new Abstraction(array(
                    'type' => 'const',
                    'name' => $reflectionParameter->getDefaultValueConstantName(),
                    'value' => $defaultValue,
                ));
            }
        }
        return $defaultValue;
    }

    /**
     * Get Parameter "name"
     *
     * @param ReflectionParameter $reflectionParameter reflectionParameter
     * @param array               $phpDoc              parsed phpDoc param info
     *
     * @return mixed
     */
    private function getParamName(ReflectionParameter $reflectionParameter, $phpDoc = array())
    {
        $name = '$'.$reflectionParameter->getName();
        if (\method_exists($reflectionParameter, 'isVariadic') && $reflectionParameter->isVariadic()) {
            // php >= 5.6
            $name = '...'.$name;
        } elseif (isset($phpDoc['name']) && \substr($phpDoc['name'], -4) === ',...') {
            // phpDoc indicates variadic...
            $name = '...'.$name;
        }
        if ($reflectionParameter->isPassedByReference()) {
            $name = '&'.$name;
        }
        return $name;
    }

    /**
     * Get param typehint
     *
     * @param ReflectionParameter $reflectionParameter reflectionParameter
     * @param array               $phpDoc              parsed phpDoc param info
     *
     * @return string|null
     */
    private function getParamTypeHint(ReflectionParameter $reflectionParameter, $phpDoc = array())
    {
        $type = null;
        if ($reflectionParameter->isArray()) {
            $type = 'array';
        } elseif (\version_compare(PHP_VERSION, '7.0.0', '>=')) {
            $type = $reflectionParameter->getType();
            if ($type instanceof \ReflectionNamedType) {
                $type = $type->getName();
            } elseif ($type) {
                $type = (string) $type;
            }
        } elseif (\preg_match('/\[\s<\w+>\s([\w\\\\]+)/s', @$reflectionParameter->__toString(), $matches)) {
            // suppressed error to avoid "Use of undefined constant STDERR" type notice
            // Parameter #0 [ <required> namespace\Type $varName ]
            $type = $matches[1];
        }
        if (!$type && isset($phpDoc['type'])) {
            $type = \strtr($phpDoc['type'], array(
                'integer' => 'int',
                'boolean' => 'bool',
            ));
        }
        return $type;
    }

    /**
     * Get return type & desc
     *
     * @param ReflectionMethod $reflectionMethod reflectionParameter
     * @param array            $phpDoc           parsed phpDoc param info
     *
     * @return array
     */
    private function getReturn(ReflectionMethod $reflectionMethod, $phpDoc)
    {
        $return = array(
            'type' => null,
            'desc' => null,
        );
        if (!empty($phpDoc['return'])) {
            $return = \array_merge($return, $phpDoc['return']);
            if ($return['type']) {
                $return['type'] = \strtr($return['type'], array(
                    'integer' => 'int',
                    'boolean' => 'bool',
                ));
            }
        }
        if (\version_compare(PHP_VERSION, '7.0', '>=')) {
            $type = $reflectionMethod->getReturnType();
            if ($type !== null) {
                $return['type'] = (string) $type;
            }
        }
        return $return;
    }

    /**
     * Get method info
     *
     * @param object           $obj              object method belongs to
     * @param ReflectionMethod $reflectionMethod ReflectionMethod instance
     *
     * @return array
     */
    private function methodInfo($obj, ReflectionMethod $reflectionMethod)
    {
        // getDeclaringClass() returns LAST-declared/overridden
        $declaringClassName = $reflectionMethod->getDeclaringClass()->getName();
        $phpDoc = $this->phpDoc->getParsed($reflectionMethod);
        $vis = 'public';
        if ($reflectionMethod->isPrivate()) {
            $vis = 'private';
        } elseif ($reflectionMethod->isProtected()) {
            $vis = 'protected';
        }
        $info = array(
            'implements' => null,
            'inheritedFrom' => $declaringClassName != \get_class($obj)
                ? $declaringClassName
                : null,
            'isAbstract' => $reflectionMethod->isAbstract(),
            'isDeprecated' => $reflectionMethod->isDeprecated() || isset($phpDoc['deprecated']),
            'isFinal' => $reflectionMethod->isFinal(),
            'isStatic' => $reflectionMethod->isStatic(),
            'params' => $this->getParams($reflectionMethod, $phpDoc),
            'phpDoc' => $phpDoc,
            'return' => $this->getReturn($reflectionMethod, $phpDoc),
            'visibility' => $vis,   // public | private | protected | debug | magic
        );
        unset($info['phpDoc']['param']);
        unset($info['phpDoc']['return']);
        return $info;
    }

    /**
     * Get defaultValue from phpDoc param
     *
     * Converts the defaultValue string to php scalar
     *
     * @param array  $param     parsed param in from @method tag
     * @param string $className className where phpDoc was found
     *
     * @return mixed
     */
    private function phpDocParamValue($param, $className = null)
    {
        if (!\array_key_exists('defaultValue', $param)) {
            return Abstracter::UNDEFINED;
        }
        $defaultValue = $param['defaultValue'];
        if (\in_array($defaultValue, array('true','false','null'))) {
            $defaultValue = \json_decode($defaultValue);
        } elseif (\is_numeric($defaultValue)) {
            // there are no quotes around value
            $defaultValue = $defaultValue * 1;
        } elseif (\preg_match('/^array\(\s*\)|\[\s*\]$/i', $defaultValue)) {
            // empty array...
            // we're not going to eval non-empty arrays...
            //    non empty array will appear as a string
            $defaultValue = array();
        } elseif (\preg_match('/^(self::)?([^\(\)\[\]]+)$/i', $defaultValue, $matches)) {
            // appears to be a constant
            if ($matches[1] && \defined($className.'::'.$matches[2])) {
                // self
                $defaultValue = new Abstraction(array(
                    'type' => 'const',
                    'name' => $matches[0],
                    'value' => \constant($className.'::'.$matches[2]),
                ));
            } elseif (\defined($defaultValue)) {
                $defaultValue = new Abstraction(array(
                    'type' => 'const',
                    'name' => $defaultValue,
                    'value' => \constant($defaultValue),
                ));
            }
        } else {
            $defaultValue = \trim($defaultValue, '\'"');
        }
        return $defaultValue;
    }
}
