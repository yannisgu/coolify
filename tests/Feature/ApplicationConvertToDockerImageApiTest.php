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
        'name' => 'convert-to-docker-image-api-test-'.Str::random(6),
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

function convertToDockerImageHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function makeGitApplicationToConvert(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'dockerfile',
        'git_repository' => 'acme/website',
        'git_branch' => 'production',
        'git_commit_sha' => '0123456789abcdef0123456789abcdef01234567',
        'dockerfile_location' => '/apps/web/Dockerfile',
        'ports_exposes' => '3000',
        'fqdn' => 'https://web.example.com',
    ], $overrides));
}

describe('PATCH /api/v1/applications/{uuid} build_pack dockerimage', function () {
    test('converts a Git based application into a Docker Image application', function () {
        $application = makeGitApplicationToConvert();

        $response = $this->withHeaders(convertToDockerImageHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'build_pack' => 'dockerimage',
                'docker_registry_image_name' => 'registry.example.com/acme/website',
                'docker_registry_image_tag' => '1f2e3d4c',
            ]);

        $response->assertOk()->assertJson(['uuid' => $application->uuid]);

        $application->refresh();
        expect($application->build_pack)->toBe('dockerimage')
            ->and($application->docker_registry_image_name)->toBe('registry.example.com/acme/website')
            ->and($application->docker_registry_image_tag)->toBe('1f2e3d4c')
            ->and($application->git_repository)->toBe('coollabsio/coolify')
            ->and($application->git_branch)->toBe('main')
            ->and($application->git_commit_sha)->toBe('HEAD')
            ->and($application->source_id)->toBeNull()
            ->and($application->source_type)->toBeNull()
            // What the application is besides its source is kept.
            ->and($application->ports_exposes)->toBe('3000')
            ->and($application->fqdn)->toBe('https://web.example.com');
    });

    test('requires an image name', function () {
        $application = makeGitApplicationToConvert(['docker_registry_image_name' => null]);

        $this->withHeaders(convertToDockerImageHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", ['build_pack' => 'dockerimage'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['docker_registry_image_name']);

        expect($application->refresh()->build_pack)->toBe('dockerfile');
    });

    test('refuses a Docker Compose application', function () {
        $application = makeGitApplicationToConvert(['build_pack' => 'dockercompose']);

        $this->withHeaders(convertToDockerImageHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'build_pack' => 'dockerimage',
                'docker_registry_image_name' => 'ghcr.io/acme/website',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['build_pack']);

        expect($application->refresh()->build_pack)->toBe('dockercompose');
    });

    test('refuses turning a Docker Image application back into a Git based one', function () {
        $application = makeGitApplicationToConvert([
            'build_pack' => 'dockerimage',
            'docker_registry_image_name' => 'ghcr.io/acme/website',
            'docker_registry_image_tag' => 'latest',
        ]);

        $this->withHeaders(convertToDockerImageHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", ['build_pack' => 'dockerfile'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['build_pack']);

        expect($application->refresh()->build_pack)->toBe('dockerimage');
    });

    test('still updates the tag of a Docker Image application', function () {
        $application = makeGitApplicationToConvert([
            'build_pack' => 'dockerimage',
            'docker_registry_image_name' => 'ghcr.io/acme/website',
            'docker_registry_image_tag' => 'latest',
        ]);

        $this->withHeaders(convertToDockerImageHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'build_pack' => 'dockerimage',
                'docker_registry_image_tag' => 'v3',
            ])
            ->assertOk();

        expect($application->refresh()->docker_registry_image_tag)->toBe('v3');
    });
});
