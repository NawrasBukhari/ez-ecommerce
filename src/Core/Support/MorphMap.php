<?php

namespace EzEcommerce\Core\Support;

use EzEcommerce\Core\Exceptions\UnregisteredMorphAliasException;
use Illuminate\Database\Eloquent\Relations\Relation;

final class MorphMap
{
    /** @var array<string, class-string> */
    private static array $aliases = [];

    /**
     * @param  array<string, class-string>  $map
     */
    public static function register(array $map): void
    {
        foreach ($map as $alias => $class) {
            self::$aliases[$alias] = $class;
        }

        Relation::morphMap(self::$aliases);
    }

    public static function aliasFor(object|string $model): string
    {
        $class = is_object($model) ? $model::class : $model;

        foreach (self::$aliases as $alias => $mapped) {
            if ($mapped === $class) {
                return $alias;
            }
        }

        throw UnregisteredMorphAliasException::for($class);
    }

    public static function classFor(string $alias): string
    {
        if (! isset(self::$aliases[$alias])) {
            throw UnregisteredMorphAliasException::for($alias);
        }

        return self::$aliases[$alias];
    }

    public static function has(string $alias): bool
    {
        return isset(self::$aliases[$alias]);
    }
}
