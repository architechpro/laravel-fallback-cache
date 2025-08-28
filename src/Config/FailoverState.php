<?php

namespace LaravelFallbackCache\Config;

class FailoverState 
{
    private static bool $hasFailedOver = false;
    
    public static function setFailedOver(bool $value): void
    {
        self::$hasFailedOver = $value;
    }
    
    public static function hasFailedOver(): bool
    {
        return self::$hasFailedOver;
    }
}
