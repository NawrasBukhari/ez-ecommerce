<?php

namespace EzEcommerce\Core\Support;

final class CanonicalJson
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data): string
    {
        return json_encode(self::sortKeys($data), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function sortKeys(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $data[$key] = self::sortKeys($value);
            }
        }

        return $data;
    }
}
