<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'ports-mappings-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'coolify-'.Str::lower(Str::random(8)),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function makePortsMappingsApplication(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'ports_mappings' => '3000:3000',
    ], $overrides));
}

describe('PATCH /api/v1/applications/{uuid} ports_mappings', function () {
    test('accepts the same port mapping formats as the UI', function (string $portsMappings) {
        $application = makePortsMappingsApplication();

        $this->withToken($this->bearerToken)
            ->patchJson("/api/v1/applications/{$application->uuid}", ['ports_mappings' => $portsMappings])
            ->assertOk();

        expect($application->fresh()->ports_mappings)->toBe($portsMappings);
    })->with([
        'plain' => '8080:80',
        'multiple' => '8080:80,8443:443',
        'bound to an IPv4 address' => '10.0.0.5:8080:80',
        'several ports on the same IP' => '10.0.0.5:80:80,10.0.0.5:443:443',
        'same port on different IPs' => '10.0.0.5:80:80,10.0.0.6:80:80',
        'same port with different protocols' => '5353:53/tcp,5353:53/udp',
        'bound to an IPv6 address' => '[::1]:8080:80',
        'port range' => '8000-8010:8000-8010',
        'random host port' => '127.0.0.1::80,127.0.0.1::81',
    ]);

    test('rejects malformed port mappings without persisting them', function (string $portsMappings) {
        $application = makePortsMappingsApplication();

        $this->withToken($this->bearerToken)
            ->patchJson("/api/v1/applications/{$application->uuid}", ['ports_mappings' => $portsMappings])
            ->assertUnprocessable()
            ->assertInvalid(['ports_mappings']);

        expect($application->fresh()->ports_mappings)->toBe('3000:3000');
    })->with([
        'missing container port' => '8080',
        'not a number' => 'abc:80',
        'unknown protocol' => '8080:80/http',
        'shell metacharacters' => '8080:80;id',
    ]);

    test('rejects publishing the same host port twice', function (string $portsMappings) {
        $application = makePortsMappingsApplication();

        $this->withToken($this->bearerToken)
            ->patchJson("/api/v1/applications/{$application->uuid}", ['ports_mappings' => $portsMappings])
            ->assertUnprocessable()
            ->assertJsonPath('errors.ports_mappings', 'Each host port can only be mapped once per IP and protocol.');

        expect($application->fresh()->ports_mappings)->toBe('3000:3000');
    })->with([
        'plain' => '8080:80,8080:81',
        'on the same IP' => '10.0.0.5:8080:80,10.0.0.5:8080:81',
        'with the same protocol' => '5353:53/udp,5353:54/udp',
    ]);

    test('clears port mappings with null', function () {
        $application = makePortsMappingsApplication();

        $this->withToken($this->bearerToken)
            ->patchJson("/api/v1/applications/{$application->uuid}", ['ports_mappings' => null])
            ->assertOk();

        expect($application->fresh()->ports_mappings)->toBeNull();
    });
});

test('POST create accepts an IP-bound port mapping', function () {
    $response = $this->withToken($this->bearerToken)
        ->postJson('/api/v1/applications/dockerimage', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'destination_uuid' => $this->destination->uuid,
            'docker_registry_image_name' => 'nginx',
            'ports_exposes' => '80',
            'ports_mappings' => '10.0.0.5:8080:80',
        ]);

    $response->assertCreated();
    expect(Application::where('uuid', $response->json('uuid'))->value('ports_mappings'))->toBe('10.0.0.5:8080:80');
});
