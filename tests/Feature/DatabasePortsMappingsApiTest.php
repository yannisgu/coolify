<?php

use App\Actions\Database\StartPostgresql;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);
    Process::fake();
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('test-token', ['*'])->plainTextToken;

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function portsMappingsApi($test)
{
    return $test->withHeaders([
        'Authorization' => 'Bearer '.$test->bearerToken,
        'Content-Type' => 'application/json',
    ]);
}

function startedPostgresqlCompose(): array
{
    $compose = null;
    Process::assertRan(function ($process) use (&$compose) {
        if (preg_match("/echo '([A-Za-z0-9+\/=]+)' \| base64 -d \| (?:sudo )?tee \S+\/docker-compose\.yml/", $process->command, $matches)) {
            $compose = Yaml::parse(base64_decode($matches[1]));
        }

        return true;
    });

    return $compose;
}

dataset('standalone database types', [
    'postgresql' => ['postgresql', fn ($env, $dest) => create_standalone_postgresql($env, $dest)],
    'mysql' => ['mysql', fn ($env, $dest) => create_standalone_mysql($env, $dest)],
    'mariadb' => ['mariadb', fn ($env, $dest) => create_standalone_mariadb($env, $dest)],
    'mongodb' => ['mongodb', fn ($env, $dest) => create_standalone_mongodb($env, $dest)],
    'redis' => ['redis', fn ($env, $dest) => create_standalone_redis($env, $dest)],
    'keydb' => ['keydb', fn ($env, $dest) => create_standalone_keydb($env, $dest)],
    'dragonfly' => ['dragonfly', fn ($env, $dest) => create_standalone_dragonfly($env, $dest)],
    'clickhouse' => ['clickhouse', fn ($env, $dest) => create_standalone_clickhouse($env, $dest)],
]);

describe('PATCH /api/v1/databases/{uuid} ports_mappings', function () {
    test('sets, returns and clears ports_mappings', function (string $type, Closure $create) {
        $database = $create($this->environment->id, $this->destination);

        portsMappingsApi($this)->patchJson("/api/v1/databases/{$database->uuid}", [
            'ports_mappings' => '10.8.0.5:5432:5432',
        ])->assertOk();
        expect($database->fresh()->ports_mappings)->toBe('10.8.0.5:5432:5432');

        portsMappingsApi($this)->getJson("/api/v1/databases/{$database->uuid}")
            ->assertOk()
            ->assertJsonPath('ports_mappings', '10.8.0.5:5432:5432');

        portsMappingsApi($this)->patchJson("/api/v1/databases/{$database->uuid}", [
            'ports_mappings' => '',
        ])->assertOk();
        expect($database->fresh()->ports_mappings)->toBeNull();
        expect($database->fresh()->ports_mappings_array)->toBe([]);
    })->with('standalone database types');

    test('clears ports_mappings with null', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination, ['ports_mappings' => '5432:5432']);

        portsMappingsApi($this)->patchJson("/api/v1/databases/{$database->uuid}", [
            'ports_mappings' => null,
        ])->assertOk();

        expect($database->fresh()->ports_mappings)->toBeNull();
    });

    test('strips whitespace from ports_mappings', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination);

        portsMappingsApi($this)->patchJson("/api/v1/databases/{$database->uuid}", [
            'ports_mappings' => ' 5432:5432 , 127.0.0.1:5433:5432 ',
        ])->assertOk();

        expect($database->fresh()->ports_mappings)->toBe('5432:5432,127.0.0.1:5433:5432');
    });

    test('accepts valid formats', function (string $value) {
        $database = create_standalone_postgresql($this->environment->id, $this->destination);

        portsMappingsApi($this)->patchJson("/api/v1/databases/{$database->uuid}", [
            'ports_mappings' => $value,
        ])->assertOk();

        expect($database->fresh()->ports_mappings)->toBe($value);
    })->with([
        'host:container' => '5432:5432',
        'ipv4' => '10.8.0.5:5432:5432',
        'ipv6' => '[::1]:5432:5432',
        'multiple' => '5432:5432,10.8.0.5:5433:5432,[fd00::1]:5434:5432',
        'protocol' => '5432:5432/tcp',
    ]);

    test('rejects invalid ports_mappings', function (mixed $value) {
        $database = create_standalone_postgresql($this->environment->id, $this->destination, ['ports_mappings' => '5432:5432']);

        portsMappingsApi($this)->patchJson("/api/v1/databases/{$database->uuid}", [
            'ports_mappings' => $value,
        ])->assertStatus(422)->assertJsonValidationErrors('ports_mappings');

        expect($database->fresh()->ports_mappings)->toBe('5432:5432');
    })->with([
        'container only' => '5432',
        'letters' => 'abc:def',
        'shell injection' => '5432:5432;rm -rf /',
        'hostname' => 'localhost:5432:5432',
        'trailing comma' => '5432:5432,',
        'unbracketed ipv6' => '::1:5432:5432',
        'not a string' => 5432,
    ]);

    test('is not allowed for sqlite databases', function () {
        $database = create_standalone_sqlite($this->environment->id, $this->destination);

        portsMappingsApi($this)->patchJson("/api/v1/databases/{$database->uuid}", [
            'ports_mappings' => '5432:5432',
        ])->assertStatus(422)->assertJsonValidationErrors('ports_mappings');
    });

    test('marks the configuration as changed so a restart is required', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination);
        $database->isConfigurationChanged(true);
        expect($database->fresh()->isConfigurationChanged())->toBeFalse();

        portsMappingsApi($this)->patchJson("/api/v1/databases/{$database->uuid}", [
            'ports_mappings' => '10.8.0.5:5432:5432',
        ])->assertOk();

        expect($database->fresh()->isConfigurationChanged())->toBeTrue();
    });
});

describe('POST /api/v1/databases/{type} ports_mappings', function () {
    test('creates a database with ports_mappings', function () {
        $response = portsMappingsApi($this)->postJson('/api/v1/databases/postgresql', [
            'server_uuid' => $this->server->uuid,
            'project_uuid' => $this->project->uuid,
            'environment_name' => $this->environment->name,
            'ports_mappings' => '10.8.0.5:5432:5432',
            'instant_deploy' => false,
        ]);

        $response->assertStatus(201);
        expect(StandalonePostgresql::whereUuid($response->json('uuid'))->first()->ports_mappings)->toBe('10.8.0.5:5432:5432');
    });

    test('creates a redis database with an empty ports_mappings', function () {
        $response = portsMappingsApi($this)->postJson('/api/v1/databases/redis', [
            'server_uuid' => $this->server->uuid,
            'project_uuid' => $this->project->uuid,
            'environment_name' => $this->environment->name,
            'ports_mappings' => '',
            'instant_deploy' => false,
        ]);

        $response->assertStatus(201);
        expect(StandaloneRedis::whereUuid($response->json('uuid'))->first()->ports_mappings)->toBeNull();
    });

    test('rejects invalid ports_mappings on create', function () {
        portsMappingsApi($this)->postJson('/api/v1/databases/postgresql', [
            'server_uuid' => $this->server->uuid,
            'project_uuid' => $this->project->uuid,
            'environment_name' => $this->environment->name,
            'ports_mappings' => 'not-a-port',
            'instant_deploy' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('ports_mappings');

        expect(StandalonePostgresql::count())->toBe(0);
    });
});

describe('generated docker compose', function () {
    test('publishes IP-prefixed port mappings', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination, [
            'ports_mappings' => '10.8.0.5:5432:5432,[fd00::1]:5433:5432',
        ]);

        StartPostgresql::run($database->fresh(), activity()->log('Starting database'));

        expect(startedPostgresqlCompose()['services'][$database->uuid]['ports'])
            ->toBe(['10.8.0.5:5432:5432', '[fd00::1]:5433:5432']);
    });

    test('publishes no ports when ports_mappings is empty', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination, ['ports_mappings' => '']);

        StartPostgresql::run($database->fresh(), activity()->log('Starting database'));

        expect(startedPostgresqlCompose()['services'][$database->uuid])->not->toHaveKey('ports');
    });
});
