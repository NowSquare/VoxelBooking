<?php

declare(strict_types=1);
namespace App\Controllers;

use App\Agent\{OperationException,Operations,PreviewStore,SessionActor,TransportAuth};
use App\Engine\{Auth,CustomerCapability,Locale,Request,Response,Version,View};
use App\Middleware\CsrfMiddleware;

final class AgentReviewController
{
    public function show(Request $request): Response
    {
        Auth::startSession();
        Locale::resolveForAdmin(null);
        $row=null;
        $error=null;
        $verificationUrl=null;
        $public=!str_starts_with($request->path(),'/admin/');
        try {
            $store=new PreviewStore();
            $row=$public?$store->forCustomerBrowser($request->getAttribute('id'))
                :$store->forReviewer(SessionActor::resolve(Operations::registry()),$request->getAttribute('id'));
        } catch(OperationException $exception) {
            $error=$exception->problemCode;
            if($error==='customer_verification_required') { $verificationUrl=(new PreviewStore())->verificationUrl($request->getAttribute('id')); }
        }
        $error ??= $request->getAttribute('review_error');
        $business=$row&&$row['tenant_id']!==null
            ?(\App\Engine\Database::query('SELECT name,timezone FROM tenants WHERE id=?',[$row['tenant_id']])[0]??null)
            :null;
        return CustomerCapability::protect(View::response('admin.connections.review',[
            'pageTitle'=>__('agent.review.title'),'version'=>Version::get(),'csrfToken'=>CsrfMiddleware::generateToken(),
            'id'=>$request->getAttribute('id'),'row'=>$row,'error'=>$error,'public'=>$public,'verificationUrl'=>$verificationUrl,
            'operation'=>$row?Operations::registry()->get($row['operation_id']):null,
            'business'=>$business,
        ],$error!==null && $error!=='review_link_required'?409:200));
    }

    public function exchange(Request $request): Response
    {
        try {
            $input=$request->jsonObject();
            (new PreviewStore())->exchangeBrowserHandle($request->getAttribute('id'),(string)($input['handle']??''));
            return CustomerCapability::protect(Response::json(['ready'=>true]));
        } catch(OperationException $error) { return TransportAuth::problem($error,$request); }
        catch(\JsonException|\UnexpectedValueException) { return TransportAuth::problem(new OperationException('invalid_json',400),$request); }
    }

    public function approve(Request $request): Response
    {
        try {
            $store=new PreviewStore();
            if(str_starts_with($request->path(),'/admin/')) {
                $store->approve(SessionActor::resolve(Operations::registry()),$request->getAttribute('id'));
            } else {
                $store->approveCustomerBrowser($request->getAttribute('id'),$request->string('consent')==='1');
            }
            return CustomerCapability::protect(Response::redirect($request->path()));
        } catch(OperationException $error) {
            $request->setAttribute('review_error',$error->problemCode);
            return $this->show($request);
        }
    }
}
