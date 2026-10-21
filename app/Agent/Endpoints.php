<?php

declare(strict_types=1);
namespace App\Agent;
final class Endpoints
{
    public static function baseUrl():string
    {
        $base=rtrim($_ENV['AGENT_BASE_URL']??((defined('VOXELBOOKING_TEST_PROCESS')&&VOXELBOOKING_TEST_PROCESS)?($_ENV['APP_TEST_URL']??app_url()):app_url()),'/');
        $parts=parse_url($base);
        if(!is_array($parts)||!isset($parts['scheme'],$parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!in_array($parts['scheme'],['https','http'],true)) { throw new OperationException('endpoint_configuration_invalid',503); }
        if($parts['scheme']!=='https'&&!in_array($parts['host'],['localhost','127.0.0.1','[::1]'],true)) { throw new OperationException('https_required',503); }
        return $base;
    }
    public static function resource(string $transport='http'):string { return self::baseUrl().($transport==='mcp'?'/mcp':'/api/agent/v2'); }
}
