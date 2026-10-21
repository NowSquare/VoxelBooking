<?php

declare(strict_types=1);

namespace App\Application\Shared;

/** Small schema builders; domain definitions retain the explicit field contract. */
final class Contract
{
    public static function object(array $properties, ?array $required = null): array
    {
        $schema=['type'=>'object', 'required'=>$required ?? array_keys($properties), 'additionalProperties'=>false];
        if($properties!==[]) { $schema['properties']=$properties; }
        return $schema;
    }
    public static function text(int $max = 255, bool $nullable = false): array
    {
        return ['type'=>$nullable ? ['string','null'] : 'string', 'maxLength'=>$max];
    }
    public static function pageInput(array $additional = []): array
    {
        return self::object(['business_id'=>self::text(26), 'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],
            'offset'=>['type'=>'integer','minimum'=>0,'maximum'=>100000], ...$additional], ['business_id']);
    }
    public static function page(array $item): array
    {
        return self::object(['items'=>['type'=>'array','items'=>$item], 'total'=>['type'=>'integer','minimum'=>0],
            'limit'=>['type'=>'integer'], 'offset'=>['type'=>'integer'], 'next_offset'=>['type'=>['integer','null']]]);
    }
}
