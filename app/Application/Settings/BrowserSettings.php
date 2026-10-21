<?php

declare(strict_types=1);
namespace App\Application\Settings;
use App\Agent\{Operations,SessionActor,OperationException};
use App\Engine\{FormState,Request,Response};

final class BrowserSettings
{
    public static function save(Request $request,string $section): Response
    {
        $businessId=(string)$request->getAttribute('tenant_id');
        $target='/admin/tenants/'.$businessId.'/settings'.($section==='general'?'':'/'.$section);
        try {
            $actor=SessionActor::resolve(Operations::registry());
            $changes=[];
            foreach(SettingsFields::schema($section) as $field=>$schema) {
                if(!$request->has($field)&&$schema['type']!=='boolean') { continue; }
                $raw=$request->string($field);
                $changes[$field]=match($schema['type']) {'boolean'=>$raw==='1','integer'=>(int)$raw,default=>$raw===''&&is_array($schema['type'])?null:$raw};
            }
            if($section==='embed'&&isset($changes['allowed_embed_domains'])) {
                $changes['allowed_embed_domains']=implode(',',array_map(static fn($domain)=>rtrim((string)preg_replace('#^https?://#','',trim($domain)),'/'),preg_split('/[\r\n,]+/',$changes['allowed_embed_domains'])));
            }
            if($section==='branding' && ($file=$request->file('logo')) && ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                $changes['logo_media_id']=\App\Application\Media\MediaService::storeBrowser($actor,$businessId,'logo',$file);
                $changes['remove_logo']=false;
            }
            SettingsService::update($actor,['business_id'=>$businessId,'expected_version'=>$request->string('expected_version'),'changes'=>$changes],$section);
            FormState::toast('success',__('admin.tenant_settings.saved'));
        } catch(OperationException $error) {
            if(in_array($error->status,[401,403,404],true)||$error->problemCode==='pattern_not_supported') {
                return \App\Engine\View::response('admin.errors.403',[
                    'user'=>\App\Engine\Auth::user(),'version'=>\App\Engine\Version::get(),'pageTitle'=>'403',
                    'csrfToken'=>\App\Middleware\CsrfMiddleware::generateToken(),
                ],$error->status===404?404:403);
            }
            $fields=[];
            foreach($error->fieldErrors as $path=>$messages) {
                $field=basename(str_replace('.','/',trim($path,'/')));
                if(array_key_exists($field,SettingsFields::schema($section))) { $fields[$field]=__('agent.validation.field'); }
            }
            FormState::flash(array_intersect_key($request->all(),SettingsFields::schema($section)),$fields);
            FormState::toast('error',__('agent.errors.'.$error->problemCode));
        }
        return Response::redirect($target);
    }
}
