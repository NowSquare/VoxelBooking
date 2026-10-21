<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Application\Shared\Access;
use App\Engine\Database;
use App\Engine\LoginToken;
use App\Engine\Mailer;
use App\Engine\Outbox;
use App\Engine\Ulid;

final class TeamService
{
    public static function list(ActorContext $actor, array $input): array
    {
        $actor = BusinessAccess::actor($actor, 'team:read', 'owner');
        $tenant = BusinessAccess::tenant($actor, $input);
        return BusinessAccess::page(
            $actor,
            'business_users',
            'tenant_id = ?',
            [$tenant['id']],
            $input,
            self::project(...)
        );
    }

    public static function get(ActorContext $actor, array $input): array
    {
        $actor = BusinessAccess::actor($actor, 'team:read', 'owner');
        $tenant = BusinessAccess::tenant($actor, $input);
        return self::project(self::row($tenant['id'], (string) ($input['id'] ?? '')));
    }

    public static function invite(
        ActorContext $actor,
        array $input,
        ?string $browserPassword = null,
        bool $sendInvitation = true
    ): array {
        return Access::transaction(function () use ($actor, $input, $browserPassword, $sendInvitation): array {
            $actor = BusinessAccess::actor($actor, 'team:manage', 'owner', true);
            $tenant = BusinessAccess::tenant($actor, $input, true);
            $actor = BusinessAccess::actor($actor, 'team:manage', 'owner', true);
            $actor->business($tenant['id']);
            return self::persist($actor, $tenant['id'], $input, $browserPassword, $sendInvitation);
        });
    }

    /** Only used inside atomic business creation; it does not widen the connection grant. */
    public static function createForNewBusiness(
        ActorContext $actor,
        string $businessId,
        array $owner,
        ?string $browserPassword = null,
        bool $sendInvitation = true
    ): array {
        $actor = BusinessAccess::actor($actor, 'team:manage', 'operator', true);
        if (!Database::pdo()->inTransaction()) {
            throw new \LogicException('New business invitation requires its creation transaction.');
        }
        return self::persist($actor, $businessId, [...$owner, 'role' => 'owner'], $browserPassword, $sendInvitation);
    }

    private static function persist(
        ActorContext $actor,
        string $businessId,
        array $input,
        ?string $browserPassword,
        bool $sendInvitation
    ): array {
        [$name, $email, $role] = self::validateInvite($input, $browserPassword);
        $id = Ulid::generate();
        try {
            Database::execute(
                "INSERT INTO business_users (id,tenant_id,name,email,password_hash,role,is_active,force_password_change) VALUES (?,?,?,?,?,?,1,1)",
                [
                    $id,
                    $businessId,
                    $name,
                    $email,
                    password_hash($browserPassword ?? bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]),
                    $role
                ]
            );
            Database::execute(
                "INSERT INTO auth_emails (email,user_type,user_id) VALUES (?,'business_user',?)",
                [$email, $id]
            );
        } catch (\PDOException $error) {
            if (str_starts_with((string) $error->getCode(), '23')) {
                throw new OperationException('identity_unavailable', 409, ['email' => 'unavailable']);
            }
            throw $error;
        }
        if ($sendInvitation) {
            Outbox::enqueue(
                'team.invitation',
                ['user_id' => $id, 'business_id' => $businessId],
                $businessId,
                'team-invite:' . $id
            );
        }
        BusinessAccess::audit($actor, 'business_user.created', 'business_user', $id, $businessId);

        return self::project(self::row($businessId, $id));
    }

    private static function validateInvite(array $input, ?string $browserPassword = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $role = $input['role'] ?? 'manager';
        if ($name === '' || mb_strlen($name) > 255) {
            BusinessAccess::invalid('name');
        }
        if ($email === '') {
            BusinessAccess::invalid('email', 'required');
        }
        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            BusinessAccess::invalid('email');
        }
        if (!in_array($role, ['owner', 'manager'], true)) {
            BusinessAccess::invalid('role');
        }
        if ($browserPassword !== null && (strlen($browserPassword) < 8 || strlen($browserPassword) > 1024)) {
            BusinessAccess::invalid('password');
        }
        if (Database::query('SELECT email FROM auth_emails WHERE email = ?', [$email]) !== []) {
            throw new OperationException('identity_unavailable', 409, ['email' => 'unavailable']);
        }

        return [$name, $email, $role];
    }

    public static function previewInvite(ActorContext $actor, array $input): array
    {
        $actor = BusinessAccess::actor($actor, 'team:manage', 'owner', true);
        $tenant = BusinessAccess::tenant($actor, $input);
        [$name, $email, $role] = self::validateInvite($input);

        return [
            'business_id' => $tenant['id'],
            'invitation' => ['name' => $name, 'email' => $email, 'role' => $role],
            'delivery' => 'email',
            'credentials_exposed' => false
        ];
    }

    public static function previewNewOwner(ActorContext $actor, array $input): array
    {
        BusinessAccess::actor($actor, 'team:manage', 'operator', true);
        [$name, $email, $role] = self::validateInvite([...$input, 'role' => 'owner']);
        return ['name' => $name, 'email' => $email, 'role' => $role];
    }

    public static function setActive(ActorContext $actor, array $input): array
    {
        return Access::transaction(function () use ($actor, $input): array {
            $actor = BusinessAccess::actor($actor, 'team:manage', 'owner', true);
            $tenant = BusinessAccess::tenant($actor, $input, true);
            $actor = BusinessAccess::actor($actor, 'team:manage', 'owner', true);
            $actor->business($tenant['id']);
            $row = self::row($tenant['id'], (string) ($input['id'] ?? ''));
            BusinessAccess::check(self::project($row), $input);
            if (!is_bool($input['is_active'] ?? null)) {
                BusinessAccess::invalid('is_active');
            }
            if (!$input['is_active']) {
                if ($actor->principalType === 'business_user' && $actor->principalId === $row['id']) {
                    throw new OperationException('self_deactivation', 409);
                }
                if (
                    $row['role'] === 'owner'
                    && $row['is_active']
                    && (int) Database::query(
                        "SELECT COUNT(*) AS total FROM business_users WHERE tenant_id = ? AND role = 'owner' AND is_active = 1",
                        [$tenant['id']]
                    )[0]['total'] <= 1
                ) {
                    throw new OperationException('last_owner', 409, nextAction: 'invite_another_owner');
                }
            }
            Database::execute(
                'UPDATE business_users SET is_active = ? WHERE id = ? AND tenant_id = ?',
                [(int) $input['is_active'], $row['id'], $tenant['id']]
            );
            BusinessAccess::audit(
                $actor,
                'business_user.' . ($input['is_active'] ? 'activated' : 'deactivated'),
                'business_user',
                $row['id'],
                $tenant['id']
            );
            return self::project(self::row($tenant['id'], $row['id']));
        });
    }

    public static function preview(ActorContext $actor, array $input): array
    {
        $actor = BusinessAccess::actor($actor, 'team:manage', 'owner', true);
        $tenant = BusinessAccess::tenant($actor, $input);
        $before = self::project(self::row($tenant['id'], (string) ($input['id'] ?? '')));
        BusinessAccess::check($before, $input);

        return [
            'business_id' => $before['business_id'],
            'version' => $before['version'],
            'before' => $before,
            'after' => array_replace($before, ['is_active' => $input['is_active']]),
            'connections_lose_access' => !$input['is_active']
        ];
    }

    public static function deliverInvitation(array $payload): array
    {
        $row = Database::query(
            "SELECT u.*, t.name AS business_name FROM business_users u JOIN tenants t ON t.id = u.tenant_id WHERE u.id = ? AND u.tenant_id = ? AND u.is_active = 1 AND t.status != 'archived'",
            [$payload['user_id'], $payload['business_id']]
        )[0] ?? null;
        if ($row === null) {
            return ['logged' => true];
        }
        // Mint only at delivery, so a delayed job never sends an already-expired link.
        $issued = LoginToken::createMagicLink($row['email'], '127.0.0.1');
        if (!$issued['success']) {
            throw new \RuntimeException('invitation_retry');
        }
        $link = app_url('/admin/login/verify?token=' . rawurlencode($issued['token']));
        $title = __('agent-business.invitation_subject', ['business' => $row['business_name']]);
        $html = '<h1>'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</h1><p>'
            . htmlspecialchars(
                __('agent-business.invitation_body', ['name' => $row['name'], 'business' => $row['business_name']]),
                ENT_QUOTES,
                'UTF-8'
            )
            . '</p><p><a href="'
            . htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
            . '">'
            . htmlspecialchars(__('agent-business.invitation_action'), ENT_QUOTES, 'UTF-8')
            . '</a></p><p>'
            . htmlspecialchars(__('auth.magic_link_email_expiry'), ENT_QUOTES, 'UTF-8')
            . '</p>';

        return Mailer::send($row['email'], $title, $html, 'team_invitation', tenantId: $row['tenant_id']);
    }

    public static function project(array $row): array
    {
        return BusinessAccess::version([
            'id' => $row['id'],
            'business_id' => $row['tenant_id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'],
            'is_active' => (bool) $row['is_active']
        ]) + ['last_login_at' => $row['last_login_at'], 'created_at' => $row['created_at']];
    }

    private static function row(string $businessId, string $id): array
    {
        return Database::query('SELECT * FROM business_users WHERE id = ? AND tenant_id = ?', [$id, $businessId])[0]
            ?? throw new OperationException('not_found', 404);
    }
}
