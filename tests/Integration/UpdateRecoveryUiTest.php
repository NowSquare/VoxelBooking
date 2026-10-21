<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\{Auth,Locale,View};
use PHPUnit\Framework\TestCase;

/** The native recovery affordance uses the existing operator/CSRF POST action. */
final class UpdateRecoveryUiTest extends TestCase
{
    public function testPendingDatabaseUpdateShowsNativeRetryFormWithCsrf():void
    {
        TestFixtures::provision();Auth::startSession();$previous=$_SESSION;
        Locale::reset();Locale::init(dirname(__DIR__,2));Locale::setLocale('en');
        View::init(dirname(__DIR__,2).'/templates');
        $_SESSION=['auth_type'=>'operator','auth_id'=>TestFixtures::OPERATOR_ID];
        try {
            $data=['pageTitle'=>'Updates','currentVersion'=>'fixture','packages'=>[],
                'lastUpdate'=>null,'phpVersion'=>PHP_VERSION,'flash'=>null,'zipAvailable'=>false,
                'git'=>['is_repo'=>true,'state'=>'migration_pending','can_update'=>true,
                    'message'=>__('admin.updates.git_msg_migration_pending'),'branch'=>'main','short_sha'=>'fixture']];
            $html=View::render('admin.updates',$data);$document=new \DOMDocument();
            @$document->loadHTML($html);$xpath=new \DOMXPath($document);
            $form=$xpath->query('//form[@action="/admin/updates/git-update"]');
            self::assertCount(1,$form);
            self::assertSame('post',$form->item(0)->getAttribute('method'));
            self::assertStringContainsString('Retry database update',$form->item(0)->textContent);
            self::assertStringContainsString('Apply the pending database migrations?',$form->item(0)->getAttribute('onsubmit'));
            $token=$xpath->query('.//input[@name="_csrf_token"]',$form->item(0));
            self::assertCount(1,$token);self::assertNotSame('',$token->item(0)->getAttribute('value'));
            self::assertStringContainsString('database migrations still need to finish',$html);
            $data['git']['state']='dirty';$data['git']['can_update']=false;
            self::assertStringNotContainsString('action="/admin/updates/git-update"',View::render('admin.updates',$data));
        } finally {$_SESSION=$previous;}
    }
}
