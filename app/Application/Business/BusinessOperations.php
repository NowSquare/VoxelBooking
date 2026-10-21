<?php

declare(strict_types=1);
namespace App\Application\Business;

use App\Agent\ActorContext;
use App\Agent\OperationDefinition;
use App\Application\Shared\Contract as S;

final class BusinessOperations
{
    public static function definitions(): array
    {
        $text = S::text(...);
        $id = ['business_id'=>$text(26)];
        $version = ['expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']];
        $page = ['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'cursor'=>$text(4096)];
        $filters = ['search'=>$text(255),'status'=>['type'=>'string','enum'=>['active','paused','archived','all']]];
        $fields = ['name'=>$text(255),'slug'=>$text(100),'email'=>['type'=>'string','format'=>'email','maxLength'=>255],
            'timezone'=>$text(100),'currency'=>$text(3),'brand_color'=>['type'=>'string','pattern'=>'^#[a-fA-F0-9]{6}$']];
        $businessOutput = S::object(array_fill_keys(BusinessService::FIELDS,$text(255))+['version'=>$text(64)]);
        $teamFields = ['name'=>$text(255),'email'=>['type'=>'string','format'=>'email','maxLength'=>255],'role'=>['type'=>'string','enum'=>['owner','manager']]];
        $teamOutput = S::object(['id'=>$text(26),...$id,...$teamFields,'is_active'=>['type'=>'boolean'],'version'=>$text(64),'last_login_at'=>$text(30,true),'created_at'=>$text(30)]);
        $applicationFields = ['business_name'=>$text(255),'contact_name'=>$text(255),'email'=>['type'=>'string','format'=>'email','maxLength'=>255],
            'phone'=>$text(50,true),'website'=>$text(2048,true),'message'=>$text(10000,true)];
        $applicationOutput = S::object(['id'=>$text(26),...$applicationFields,'status'=>['type'=>'string','enum'=>['pending','approved','rejected']],'admin_notes'=>$text(10000,true),'version'=>$text(64),'created_at'=>$text(30),'reviewed_at'=>$text(30,true),'reviewed_by'=>$text(26,true)]);
        $definitions = [
            self::op('businesses_list','List businesses','C02',['businesses:read'],'staff',true,S::object([...$page,...$filters],[]),self::page($businessOutput),BusinessService::list(...)),
            self::op('business_get','Get business','C02',['businesses:read'],'staff',true,S::object($id,[]),$businessOutput,BusinessService::get(...),archived:true),
            self::op('business_create','Create business','C02',['businesses:manage'],'operator',false,
                S::object([...$fields,'booking_pattern'=>['type'=>'string','enum'=>['timeslot','resource','capacity','event']],
                    'owner'=>S::object(['name'=>$text(255),'email'=>['type'=>'string','format'=>'email','maxLength'=>255]])],['name','email']),$businessOutput,BusinessService::create(...),preview:BusinessService::previewCreate(...),review:true,effects:['notifications']),
            self::op('business_update','Update business','C02',['businesses:manage'],'operator',false,S::object([...$id,...$version,...$fields],['expected_version']),$businessOutput,BusinessService::update(...),
                preview:static fn(ActorContext $actor,array $input):array=>BusinessService::preview($actor,$input,'update')),
            self::op('business_set_status','Archive or activate business','C02',['businesses:manage'],'operator',false,S::object([...$id,...$version,'status'=>['type'=>'string','enum'=>['active','archived']]],['expected_version','status']),$businessOutput,BusinessService::setStatus(...),
                preview:static fn(ActorContext $actor,array $input):array=>BusinessService::preview($actor,$input,'status'),review:true,destructive:true,archived:true),
            self::op('businesses_export','Export businesses','C02',['businesses:read'],'operator',true,S::object($filters,[]),
                S::object(['filename'=>$text(255),'content_type'=>$text(100),'row_count'=>['type'=>'integer'],'content'=>$text(4000000)]),BusinessService::export(...),archived:true),
            self::op('team_list','List business team','C28',['team:read'],'owner',true,S::object([...$id,...$page],[]),self::page($teamOutput),TeamService::list(...)),
            self::op('team_member_get','Get team member','C28',['team:read'],'owner',true,S::object([...$id,'id'=>$text(26)],['id']),$teamOutput,TeamService::get(...)),
            self::op('team_invite','Invite team member','C28',['team:manage'],'owner',false,S::object([...$id,...$teamFields],['name','email','role']),$teamOutput,TeamService::invite(...),preview:TeamService::previewInvite(...),review:true,effects:['notifications']),
            self::op('team_member_set_active','Set team member access','C28',['team:manage'],'owner',false,S::object([...$id,'id'=>$text(26),...$version,'is_active'=>['type'=>'boolean']],['id','expected_version','is_active']),$teamOutput,TeamService::setActive(...),preview:TeamService::preview(...),review:true,destructive:true),
            self::op('application_submit','Request business access','C03',[],'public',false,S::object($applicationFields,['business_name','contact_name','email']),S::object(['accepted'=>['type'=>'boolean','const'=>true],'status'=>['type'=>'string','const'=>'received']]),ApplicationService::submit(...)),
            self::op('applications_list','List access requests','C03',['applications:read'],'operator',true,S::object([...$page,'status'=>['type'=>'string','enum'=>['pending','approved','rejected','all']]],[]),self::page($applicationOutput),ApplicationService::list(...)),
            self::op('application_get','Review access request','C03',['applications:read'],'operator',true,S::object(['id'=>$text(26)]),$applicationOutput,ApplicationService::get(...)),
            self::op('application_review','Approve or reject access request','C03',['applications:manage'],'operator',false,S::object(['id'=>$text(26),...$version,'decision'=>['type'=>'string','enum'=>['approved','rejected']],'admin_notes'=>$text(10000,true)],['id','expected_version','decision']),$applicationOutput,ApplicationService::review(...),preview:ApplicationService::preview(...),review:true),
            self::op('business_dashboard','View business dashboard','C04',['reports:read','bookings:read'],'staff',true,S::object($id,[]),self::dashboardSchema(),static fn(ActorContext $actor,array $input):array=>DashboardService::query($actor,$input)),
            self::op('operator_dashboard','View operator dashboard','C04',['reports:read','bookings:read'],'operator',true,S::object([],[]),self::dashboardSchema(),static fn(ActorContext $actor,array $input):array=>DashboardService::query($actor,$input,true)),
        ];
        return $definitions;
    }
    private static function page(array $record): array
    {
        return S::object(['items'=>['type'=>'array','maxItems'=>100,'items'=>$record],'next_cursor'=>S::text(4096,true)]);
    }
    private static function dashboardSchema(): array
    {
        $text = S::text(...); $integer = ['type'=>'integer']; $nullable = ['type'=>['integer','null']];
        $booking = S::object(['id'=>$text(26),'business_id'=>$text(26,true),'customer_name'=>$text(255,true),'status'=>$text(30),'booking_pattern'=>$text(20),
            'start_datetime'=>['type'=>'string','format'=>'date-time'],'end_datetime'=>['type'=>'string','format'=>'date-time'],'service_name'=>$text(255,true),'resource_name'=>$text(255,true),'event_name'=>$text(255,true)]);
        $staff = S::object(['id'=>$text(26),'name'=>$text(255),'title'=>$text(255,true),'windows'=>['type'=>'array','items'=>S::object(['start'=>$text(5),'end'=>$text(5)])]]);
        $counts = S::object(['active'=>$integer,'paused'=>$integer,'archived'=>$integer,'total'=>$integer]); $counts['type']=['object','null'];
        return S::object(['business_id'=>$text(26,true),'timezone'=>$text(100),'generated_at'=>['type'=>'string','format'=>'date-time'],'date_basis'=>$text(255),
            'metrics'=>S::object(['today_bookings'=>$integer,'week_bookings'=>$integer,'month_bookings'=>$nullable,'today_change'=>$integer,'week_change'=>$integer,'customers'=>$nullable,'pending_approvals'=>$integer,'upcoming_24h'=>$nullable,'businesses'=>$counts]),
            'upcoming'=>['type'=>'array','maxItems'=>5,'items'=>$booking],'today_schedule'=>['type'=>'array','maxItems'=>500,'items'=>$booking],
            'today_schedule_truncated'=>['type'=>'boolean'],'staff_working_today'=>['type'=>['array','null'],'items'=>$staff],
            'setup_actions'=>['type'=>'array','items'=>S::object(['key'=>$text(100),'url'=>$text(2048)])]]);
    }
    private static function op(string $id,string $title,string $capability,array $scopes,string $policy,bool $read,array $input,array $output,\Closure $handler,?\Closure $preview=null,bool $review=false,bool $destructive=false,bool $archived=false,array $effects=[]): OperationDefinition
    {
        return new OperationDefinition(operationId:$id,title:$title,description:$title.'. '.match($capability){'C02'=>'Business creation is operator-only. Adding an owner also needs team:manage. A new business is not added to the existing connection grant.','C03'=>'Application approval changes request status; business provisioning is a separate action.','C04'=>'Uses the same date boundaries and metrics as the browser dashboard. Booking values are not payment revenue.',default=>'Current owner or operator access is required. Invitations deliver a browser sign-in link without exposing credentials.'},
            inputSchema:$input,outputSchema:$output,requiredScopes:$scopes,actorPolicy:$policy,readOnly:$read,destructive:$destructive,confirmationPolicy:$review?'review':'none',
            patterns:[],documentationPage:'business-and-team-tools',capabilityIds:[$capability],acceptanceTestIds:array_values(array_unique(['A02','A03','A04','A41','A46','A47',...match($capability){'C02'=>['A13','A14','A16','A40'],'C03'=>['A30','A40'],'C04'=>[],default=>['A13','A16','A31','A32','A40']}])),
            httpMethod:$read?'GET':'POST',httpPath:$id==='application_submit'?'/public/access-request':'/operations/'.$id,handler:$handler,sideEffects:$effects,errorCodes:['validation_failed','forbidden','not_found','version_conflict','conflict','insufficient_scope','identity_unavailable','last_owner'],
            demoAllowed:$read,previewHandler:$preview,allowArchivedBusiness:$archived);
    }
}
