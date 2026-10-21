<?php

declare(strict_types=1);
namespace Tests\Support;
use App\Engine\Database;
use Tests\Integration\TestFixtures;
final class CustomerFixtureState
{
    public static function restore():void
    {
        TestEnvironment::assertDatabase();
        Database::execute("UPDATE tenants SET status='active',booking_pattern='timeslot',allow_cancellation=1,allow_rescheduling=1,cancellation_hours_before=24,rescheduling_hours_before=24,min_advance_hours=0,max_advance_days=365,requires_consent=1,booking_requires_approval=0,custom_fields=NULL WHERE id=?",[TestFixtures::BUSINESS_TENANT_ID]);
        Database::execute("INSERT IGNORE INTO customers (id,tenant_id,name,email) VALUES (?,?,'Test Customer','customer@example.com')",[TestFixtures::CUSTOMER_ID,TestFixtures::BUSINESS_TENANT_ID]);
        Database::execute("INSERT IGNORE INTO bookings (id,tenant_id,booking_pattern,customer_id,start_datetime,end_datetime,status,source) VALUES (?,?,'timeslot',?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 DAY),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 73 HOUR),'confirmed','web')",[TestFixtures::BOOKING_ID,TestFixtures::BUSINESS_TENANT_ID,TestFixtures::CUSTOMER_ID]);
        Database::execute("UPDATE customers SET name='Test Customer',email='customer@example.com',phone=NULL,notes=NULL,is_anonymized=0,anonymized_at=NULL,deletion_requested_at=NULL WHERE id=?",[TestFixtures::CUSTOMER_ID]);
        Database::execute("UPDATE bookings SET status='confirmed',booking_pattern='timeslot',rescheduled_to_id=NULL,cancelled_at=NULL,cancellation_reason=NULL,capacity_slot_id=NULL,resource_id=NULL,event_id=NULL,start_datetime=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 DAY),end_datetime=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 73 HOUR),party_size=1 WHERE id=?",[TestFixtures::BOOKING_ID]);
    }
}
