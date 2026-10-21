<?php

declare(strict_types=1);

namespace Tests\Integration\Transport;

use App\Agent\ActorContext;
use App\Engine\Database;
use Tests\Support\Transport\TransportTestCase;

/** Installation-wide operator commands over HTTP v2 and native MCP. */
final class SystemTransportTest extends TransportTestCase
{
    private const SETTINGS = ['app_name', 'system_settings_revision'];

    private ActorContext $session;
    private array $settings = [];
    private array $jobs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $this->operatorSession([]);
        foreach (self::SETTINGS as $key) {
            $this->settings[$key] = Database::query('SELECT value FROM settings WHERE `key`=?', [$key])[0]['value'] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->settings as $key => $value) {
            $value === null ? Database::execute('DELETE FROM settings WHERE `key`=?', [$key]) : Database::upsertSetting($key, $value);
        }
        foreach ($this->jobs as $id) {
            Database::execute("UPDATE system_job_requests SET status='cancelled', finished_at=UTC_TIMESTAMP() WHERE id=? AND status='queued'", [$id]);
        }
        parent::tearDown();
    }

    public function testSystemSettingsUpdateOverHttpAndMcp(): void
    {
        $client = $this->connect($this->session, ['system_settings_get', 'system_settings_update']);
        foreach (self::TRANSPORTS as $transport) {
            $before = $this->read($this->session, 'system_settings_get', ['section' => 'general']);
            $name = 'Installation over ' . $transport;
            $data = $this->call($transport, $client, 'system_settings_update', ['section' => 'general', 'expected_version' => $before['version'], 'changes' => ['app_name' => $name]])['data'];
            self::assertSame('general', $data['section']);
            self::assertSame($name, $data['values']['app_name']);
            self::assertNotSame($before['version'], $data['version']);
            self::assertSame($name, Database::query("SELECT value FROM settings WHERE `key`='app_name'")[0]['value'], $transport);
        }
    }

    /** The request is only queued; the job runner never starts here, and each request is cancelled afterwards. */
    public function testJobsRunOverHttpAndMcp(): void
    {
        $client = $this->connect($this->session, ['jobs_run', 'job_request_get']);
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $client, 'jobs_run', ['jobs' => ['outbox_cleanup']])['data'];
            $this->jobs[] = $data['id'];
            self::assertSame('queued', $data['status']);
            self::assertSame(['outbox_cleanup'], $data['jobs']);
            self::assertSame($data, $this->read($this->session, 'job_request_get', ['id' => $data['id']]), $transport);
            Database::execute("UPDATE system_job_requests SET status='cancelled', finished_at=UTC_TIMESTAMP() WHERE id=?", [$data['id']]);
        }
    }
}
