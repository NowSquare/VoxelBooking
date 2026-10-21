<?php

declare(strict_types=1);

namespace App\Application\Customers;

use App\Agent\{ActorContext, OperationException};
use App\Application\Shared\{Access, Csv, Cursor};
use App\Engine\Database;
use App\Models\Customer;

final class CustomerDirectory
{
    public static function search(ActorContext $actor, array $input, ?int $browserOffset = null): array
    {
        $businessId = $input['business_id'];
        Access::tenant($actor, $businessId);
        $limit = max(1, min($browserOffset===null?100:1000, (int)($input['limit'] ?? 25)));
        if($browserOffset!==null&&$actor->authMode!=='session') { throw new OperationException('forbidden',403); }
        $search = mb_substr(trim($input['search'] ?? ''), 0, 200) ?: null;
        $context=Cursor::context($actor,'customer_list',[$businessId,$search]);
        $cursor=Cursor::read($input['cursor']??null,$context);
        $where='tenant_id=? AND is_anonymized=0';$params=[$businessId];
        if($search!==null) { $where.=' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)';$params=[...$params,...array_fill(0,3,'%'.$search.'%')]; }
        $through=$cursor['through']??(Database::query('SELECT MAX(id) AS id FROM customers WHERE '.$where,$params)[0]['id']??'');
        $total=(int)Database::query('SELECT COUNT(*) AS n FROM customers WHERE '.$where.' AND id<=?',[...$params,$through])[0]['n'];
        $rows=Database::query('SELECT id,name,email,phone,booking_count,last_booking_at,created_at FROM customers WHERE '.$where.
            ' AND id<=?'.($cursor?' AND id<?':'').' ORDER BY id DESC LIMIT '.($limit+1).($browserOffset!==null?' OFFSET '.max(0,min(100000,$browserOffset)):''),
            [...$params,$through,...($cursor?[$cursor['after']]:[])]);
        $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
        return ['items'=>array_map(self::project(...),$rows),'total'=>$total,
            'next_cursor'=>$more?Cursor::next($context,end($rows)['id'],$through,$cursor):null];
    }

    public static function detail(ActorContext $actor, string $businessId, string $customerId): array
    {
        Access::tenant($actor, $businessId);
        $customer = Database::query('SELECT id, name, email, phone, notes, booking_count, last_booking_at, created_at FROM customers WHERE id = ? AND tenant_id = ? AND is_anonymized = 0',
            [$customerId, $businessId])[0] ?? null;
        if ($customer === null) { throw new OperationException('not_found', 404); }
        // Live counts match the full history, including more than the first page.
        $stats = Database::query('SELECT COUNT(*) AS n, MAX(start_datetime) AS latest FROM bookings WHERE customer_id = ? AND tenant_id = ?', [$customerId, $businessId])[0];
        $customer['booking_count'] = (int)$stats['n'];
        $customer['last_booking_at'] = $stats['latest'];
        return [...self::project($customer),'notes'=>$customer['notes']];
    }

    public static function history(ActorContext $actor, array $input): array
    {
        self::detail($actor, $input['business_id'], $input['customer_id']);
        $limit = max(1, min(100, (int)($input['limit'] ?? 25)));
        $context=Cursor::context($actor,'customer_history',[$input['business_id'],$input['customer_id']]);
        $cursor=Cursor::read($input['cursor']??null,$context);
        $through=$cursor['through']??(Database::query('SELECT MAX(id) AS id FROM bookings WHERE customer_id=? AND tenant_id=?',[$input['customer_id'],$input['business_id']])[0]['id']??'');
        $items = Database::query('SELECT b.id, b.booking_pattern, b.start_datetime, b.end_datetime, b.status,
            s.name AS service_name, r.name AS resource_name, e.name AS event_name
            FROM bookings b LEFT JOIN services s ON s.id = b.service_id AND s.tenant_id = b.tenant_id
            LEFT JOIN resources r ON r.id = b.resource_id AND r.tenant_id = b.tenant_id
            LEFT JOIN events e ON e.id = b.event_id AND e.tenant_id = b.tenant_id
            WHERE b.customer_id = ? AND b.tenant_id = ? AND b.id<=?'.($cursor?' AND b.id<?':'').' ORDER BY b.id DESC LIMIT '.($limit+1),
            [$input['customer_id'], $input['business_id'],$through,...($cursor?[$cursor['after']]:[])]);
        $total = (int)Database::query('SELECT COUNT(*) AS n FROM bookings WHERE customer_id = ? AND tenant_id = ?', [$input['customer_id'], $input['business_id']])[0]['n'];
        $more=count($items)>$limit;$items=array_slice($items,0,$limit);
        return ['items'=>$items, 'total'=>$total,'next_cursor'=>$more?Cursor::next($context,end($items)['id'],$through,$cursor):null];
    }

    public static function export(ActorContext $actor, array $input): array
    {
        Access::tenant($actor, $input['business_id']);
        // Ten bounded pages make one export chunk, using the same stable traversal.
        $rows=[];$cursor=$input['cursor']??null;
        for($page=0;$page<10;$page++) {
            $result=self::search($actor,[...$input,'cursor'=>$cursor,'limit'=>100]);
            $rows=[...$rows,...$result['items']];$cursor=$result['next_cursor'];
            if($cursor===null) { break; }
        }
        return ['filename'=>'customers-' . gmdate('Y-m-d') . '.csv', 'content_type'=>'text/csv; charset=UTF-8',
            'content'=>Csv::encode(['Name','Email','Phone','Bookings','Last booking','Registered'], array_map(static fn(array $row)=>[
                $row['name'], $row['email'], $row['phone'], $row['booking_count'], $row['last_booking_at'], $row['created_at']], $rows)),
            'row_count'=>count($rows), 'next_cursor'=>$cursor];
    }

    private static function project(array $row): array
    {
        return ['id'=>$row['id'], 'name'=>$row['name'], 'email'=>$row['email'], 'phone'=>$row['phone'],
            'booking_count'=>(int)$row['booking_count'], 'last_booking_at'=>$row['last_booking_at'], 'created_at'=>$row['created_at']];
    }
}
