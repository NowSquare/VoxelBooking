<?php

declare(strict_types=1);
namespace Tests\Unit\Agent;
use App\Agent\{SchemaValidator,OperationException};
use App\Application\Settings\SettingsFields;
use PHPUnit\Framework\TestCase;
final class SettingsValidationTest extends TestCase
{
    public function testSettingsChangesCannotSmuggleAuthorityOrPaths(): void
    {
        $this->expectException(OperationException::class);
        (new SchemaValidator())->validate(SettingsFields::input('branding',true),['business_id'=>'a','expected_version'=>str_repeat('a',64),'changes'=>['logo_path'=>'../../secret']]);
    }
    public function testPrivacyPolicyCannotCarryAnExecutableUrl(): void
    {
        $this->expectException(OperationException::class);
        \App\Application\Settings\SettingsService::validateChanges('privacy',['privacy_policy_url'=>'javascript:alert(1)']);
    }
    public function testEmbedRejectsPathsAndWildcardDomains(): void
    {
        $this->expectException(OperationException::class);
        \App\Application\Settings\SettingsService::validateChanges('embed',['allowed_embed_domains'=>'*.example.com/secret']);
    }
}
