<?php
declare(strict_types=1);
namespace App\Controllers\Admin;

use App\Agent\{OperationException,Operations,SchemaValidator,SessionActor};
use App\Application\Integrations\{WebhookOperations,WebhookService};
use App\Application\Shared\Contract;
use App\Engine\{Auth,CustomerCapability,FormState,Request,Response,Version,View};
use App\Middleware\CsrfMiddleware;

final class WebhooksController
{
    public function index(Request $request): Response
    {
        try {
            $businessId=$request->getAttribute('tenant_id');
            $actor=SessionActor::resolve(Operations::registry());
            $items=WebhookService::listing($actor,['business_id'=>$businessId])['items'];
            return $this->render('index',$businessId,['items'=>$items,'flash'=>FormState::getToast()]);
        }catch(OperationException $error) { return $this->failure($error); }
    }
    public function secret(Request $request): Response
    {
        try {
            $businessId=$request->getAttribute('tenant_id');
            $actor=SessionActor::resolve(Operations::registry());
            $subscription=WebhookService::get($actor,['business_id'=>$businessId,'subscription_id'=>$request->getAttribute('id')]);
            $secret=$request->method()==='POST'?WebhookService::reveal($actor,$businessId,$subscription['id']):null;
            return $this->render('secret',$businessId,['subscription'=>$subscription,'secret'=>$secret]);
        }catch(OperationException $error) { return $this->failure($error); }
    }
    public function create(Request $request): Response { return $this->mutate($request,'create'); }
    public function update(Request $request): Response { return $this->mutate($request,'update'); }
    public function rotate(Request $request): Response { return $this->mutate($request,'rotate'); }
    public function retry(Request $request): Response { return $this->mutate($request,'retry'); }
    public function deliveries(Request $request): Response
    {
        try {
            $businessId=$request->getAttribute('tenant_id');$actor=SessionActor::resolve(Operations::registry());
            $input=['business_id'=>$businessId,'subscription_id'=>$request->getAttribute('id'),'after_id'=>$request->string('after_id')];
            $subscription=WebhookService::get($actor,$input);
            return $this->render('deliveries',$businessId,['subscription'=>$subscription,'deliveries'=>WebhookService::deliveries($actor,$input)]);
        }catch(OperationException $error) { return $this->failure($error); }
    }
    private function mutate(Request $request,string $action): Response
    {
        $businessId=$request->getAttribute('tenant_id');
        try {
            $actor=SessionActor::resolve(Operations::registry());$input=['business_id'=>$businessId];
            if(in_array($action,['update','rotate'],true)) {
                $input+=['subscription_id'=>$request->getAttribute('id'),'expected_version'=>$request->string('expected_version')];
            }
            if(in_array($action,['create','update'],true)) {
                $values=['name'=>$request->string('name'),'endpoint'=>$request->string('endpoint'),'event_types'=>$request->all()['event_types']??[]];
                if($action==='update') { $values['is_active']=$request->string('is_active')==='1'; }
                $fields=WebhookOperations::fields();if($action==='create') { unset($fields['is_active']); }
                (new SchemaValidator())->validate(Contract::object($fields),$values);
                $input+=$values;
            }
            if($action==='retry') { $input['delivery_id']=$request->getAttribute('id'); }
            $result=WebhookService::$action($actor,$input);
            if(in_array($action,['create','rotate'],true)) {
                return CustomerCapability::protect(Response::redirect('/admin/tenants/'.$businessId.'/webhooks/'.$result['id'].'/secret'));
            }
            FormState::toast('success',__('agent-webhooks.saved'));
        }catch(OperationException $error) {
            $message=__('agent-webhooks.errors.'.$error->problemCode);
            if(str_starts_with($message,'agent-webhooks.')) { $message=__('agent.errors.'.$error->problemCode); }
            FormState::toast('error',$message);
        }
        return CustomerCapability::protect(Response::redirect('/admin/tenants/'.$businessId.'/webhooks'));
    }
    private function render(string $template,string $businessId,array $data): Response
    {
        return CustomerCapability::protect(View::response('admin.webhooks.'.$template,[
            'user'=>Auth::user(),'version'=>Version::get(),'csrfToken'=>CsrfMiddleware::generateToken(),
            'pageTitle'=>__('agent-webhooks.title'),'activePage'=>'webhooks','tenantId'=>$businessId,...$data,
        ]));
    }
    private function failure(OperationException $error): Response
    {
        return CustomerCapability::protect(View::response('admin.errors.403',[
            'user'=>Auth::user(),'version'=>Version::get(),'csrfToken'=>CsrfMiddleware::generateToken(),'pageTitle'=>(string)$error->status,
        ],$error->status));
    }
}
