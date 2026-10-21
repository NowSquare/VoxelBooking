<?php

declare(strict_types=1);
namespace Tests\Unit\Agent;
use App\Agent\SchemaValidator;
use App\Agent\OperationException;
use App\Agent\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class ContractValidationTest extends TestCase
{
    public function testStrictTypedInputsRejectIrrelevantFields(): void
    {
        $this->expectException(OperationException::class);
        (new SchemaValidator())->validate(['type'=>'object','required'=>['party_size'],'properties'=>['party_size'=>['type'=>'integer','minimum'=>1]],'additionalProperties'=>false], ['party_size'=>'2','is_admin'=>true]);
    }
    public function testMoneyStaysDecimalString(): void
    {
        (new SchemaValidator())->validate(['type'=>'object','properties'=>['price'=>['type'=>'string','pattern'=>'^[0-9]+\\.[0-9]{2}$']],'required'=>['price']], ['price'=>'180.00']);
        $this->expectException(OperationException::class);
        (new SchemaValidator())->validate(['type'=>'string'], 180.00);
    }
    public function testDigestSortsObjectsButPreservesListOrder(): void
    {
        self::assertSame(CanonicalJson::hash(['b'=>2,'a'=>1]), CanonicalJson::hash(['a'=>1,'b'=>2]));
        self::assertNotSame(CanonicalJson::hash(['a'=>[1,2]]), CanonicalJson::hash(['a'=>[2,1]]));
    }
}
