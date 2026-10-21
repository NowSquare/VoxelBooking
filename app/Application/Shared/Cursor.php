<?php
declare(strict_types=1);
namespace App\Application\Shared;
use App\Agent\{ActorContext,CanonicalJson,OperationException,PayloadCipher};

/** One-hour traversal, bound to the caller, grant, business, query and immutable ordering. */
final class Cursor
{
    public static function context(ActorContext $actor,string $kind,array $filters): string
    {
        return CanonicalJson::hash([$actor->principalType,$actor->principalId,$actor->connectionId,$kind,$filters]);
    }
    public static function read(?string $value,string $context): ?array
    {
        if($value===null||$value==='') { return null; }
        try { $cursor=(new PayloadCipher())->decrypt($value); }
        catch(\Throwable) { throw new OperationException('invalid_cursor',422); }
        if(($cursor['context']??null)!==$context||($cursor['expires']??0)<time()
            ||!is_string($cursor['after']??null)||!is_string($cursor['through']??null)) {
            throw new OperationException('invalid_cursor',422);
        }
        return $cursor;
    }
    public static function next(string $context,string $after,string $through,?array $previous): string
    {
        return (new PayloadCipher())->encrypt(['context'=>$context,'after'=>$after,'through'=>$through,'expires'=>$previous['expires']??time()+3600]);
    }
}
