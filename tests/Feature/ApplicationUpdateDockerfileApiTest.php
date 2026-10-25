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
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'update-dockerfile-api-test-'.Str::random(6),
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

function updateDockerfileHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function makeApplicationForDockerfileUpdate(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'dockerfile',
        'dockerfile' => "FROM nginx:1.27\nEXPOSE 80\n",
        'ports_exposes' => '80',
    ], $overrides));
}

describe('PATCH /api/v1/applications/{uuid} dockerfile', function () {
    test('replaces the Dockerfile of an application built from Dockerfile content', function () {
        $application = makeApplicationForDockerfileUpdate();
        $dockerfile = "FROM nginx:1.29\nCOPY index.html /usr/share/nginx/html/\nEXPOSE 80\n";

        $response = $this->withHeaders(updateDockerfileHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'dockerfile' => base64_encode($dockerfile),
            ]);

        $response->assertOk()->assertJson(['uuid' => $application->uuid]);

        $application->refresh();
        expect($application->dockerfile)->toBe($dockerfile)
            ->and($application->build_pack)->toBe('dockerfile')
            ->and($application->ports_exposes)->toBe('80');
    });

    test('resets the healthcheck taken from a HEALTHCHECK the new Dockerfile no longer has', function () {
        $application = makeApplicationForDockerfileUpdate([
            'dockerfile' => "FROM nginx:1.27\nHEALTHCHECK --interval=30s CMD curl -f http://localhost/\n",
            'custom_healthcheck_found' => true,
            'health_check_interval' => 30,
        ]);

        $this->withHeaders(updateDockerfileHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'dockerfile' => base64_encode("FROM nginx:1.29\nEXPOSE 80\n"),
            ])
            ->assertOk();

        $application->refresh();
        expect((bool) $application->custom_healthcheck_found)->toBeFalse()
            ->and($application->health_check_interval)->toBe(5);
    });

    test('refuses a Dockerfile that is not base64 encoded', function () {
        $application = makeApplicationForDockerfileUpdate();

        $this->withHeaders(updateDockerfileHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'dockerfile' => "FROM nginx:1.29\nEXPOSE 80\n",
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.dockerfile', 'The dockerfile should be base64 encoded and not empty.');

        expect($application->refresh()->dockerfile)->toBe("FROM nginx:1.27\nEXPOSE 80\n");
    });

    test('refuses an empty Dockerfile', function () {
        $application = makeApplicationForDockerfileUpdate();

        $this->withHeaders(updateDockerfileHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'dockerfile' => base64_encode("  \n"),
            ])
            ->assertStatus(422);

        expect($application->refresh()->dockerfile)->toBe("FROM nginx:1.27\nEXPOSE 80\n");
    });

    test('refuses a Dockerfile on an application built from a Git repository', function () {
        $application = makeApplicationForDockerfileUpdate([
            'dockerfile' => null,
            'git_repository' => 'acme/website',
            'git_branch' => 'main',
            'dockerfile_location' => '/Dockerfile',
        ]);

        $this->withHeaders(updateDockerfileHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'dockerfile' => base64_encode("FROM nginx:1.29\n"),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.dockerfile', 'The dockerfile can only be changed on applications built from Dockerfile content.');

        expect($application->refresh()->dockerfile)->toBeNull();
    });
});
