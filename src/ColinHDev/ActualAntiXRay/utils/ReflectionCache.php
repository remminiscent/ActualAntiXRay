<?php

namespace ColinHDev\ActualAntiXRay\utils;

use ReflectionProperty;

class ReflectionCache {

    private static array $properties = [];

    public static function get(string $className, string $propertyName) : ReflectionProperty {
        return self::$properties[$className][$propertyName] ??= new ReflectionProperty($className, $propertyName);
    }
}
