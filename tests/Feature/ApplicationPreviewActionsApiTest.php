<?php

use App\Actions\Application\StopApplication;
use App\Actions\Application\StopApplicationPreview;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('preview-actions-test', ['deploy'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
    ]);
});

function makeActionPreview(Application $application, int $pullRequestId = 42): ApplicationPreview
{
    return ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => $pullRequestId,
        'pull_request_html_url' => '',
        'git_type' => 'github',
    ]);
}

describe('POST /api/v1/applications/{uuid}/start with pull_request_id', function () {
    test('creates a missing preview and queues its deployment', function () {
        $response = $this->withToken($this->token)
            ->postJson("/api/v1/applications/{$this->application->uuid}/start?pull_request_id=42&git_type=github");

        $response->assertCreated()
            ->assertJsonPath('preview.pull_request_id', 42);

        $preview = $this->application->previews()->where('pull_request_id', 42)->first();
        expect($preview)->not->toBeNull()
            ->and($preview->git_type)->toBe('github');

        $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', $response->json('deployment_uuid'))->first();
        expect($deployment)->not->toBeNull()
            ->and($deployment->pull_request_id)->toBe(42)
            ->and((bool) $deployment->restart_only)->toBeFalse();
    });

    test('deploys an existing preview with the pr alias', function () {
        makeActionPreview($this->application);

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/applications/{$this->application->uuid}/start?pr=42&force=true");

        $response->assertOk();
        expect($this->application->previews()->count())->toBe(1);

        $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', $response->json('deployment_uuid'))->first();
        expect($deployment->pull_request_id)->toBe(42)
            ->and((bool) $deployment->force_rebuild)->toBeTrue()
            ->and($deployment->git_type)->toBe('github');
    });

    test('requires git_type for a new preview of an application without a GitHub or GitLab App source', function () {
        $this->withToken($this->token)
            ->postJson("/api/v1/applications/{$this->application->uuid}/start?pull_request_id=42")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('git_type');

        expect($this->application->previews()->count())->toBe(0);
    });

    test('still deploys the application without pull_request_id', function () {
        $response = $this->withToken($this->token)
            ->postJson("/api/v1/applications/{$this->application->uuid}/start");

        $response->assertOk()->assertJsonPath('message', 'Deployment request queued.');
        expect(ApplicationDeploymentQueue::query()->where('deployment_uuid', $response->json('deployment_uuid'))->value('pull_request_id'))->toBe(0);
    });
});

describe('POST /api/v1/applications/{uuid}/restart with pull_request_id', function () {
    test('queues a restart of the preview', function () {
        makeActionPreview($this->application);

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/applications/{$this->application->uuid}/restart?pull_request_id=42");

        $response->assertOk()->assertJsonPath('message', 'Restart request queued.');

        $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', $response->json('deployment_uuid'))->first();
        expect($deployment->pull_request_id)->toBe(42)
            ->and((bool) $deployment->restart_only)->toBeTrue()
            ->and($deployment->git_type)->toBe('github');
    });

    test('returns 404 when the preview does not exist', function () {
        $this->withToken($this->token)
            ->postJson("/api/v1/applications/{$this->application->uuid}/restart?pull_request_id=42")
            ->assertNotFound()
            ->assertJsonPath('message', 'Preview for pull request 42 not found.');

        expect(ApplicationDeploymentQueue::count())->toBe(0)
            ->and($this->application->previews()->count())->toBe(0);
    });
});

describe('POST /api/v1/applications/{uuid}/stop with pull_request_id', function () {
    test('stops only the preview', function () {
        $preview = makeActionPreview($this->application);

        $this->withToken($this->token)
            ->postJson("/api/v1/applications/{$this->application->uuid}/stop?pull_request_id=42")
            ->assertOk()
            ->assertJsonPath('message', 'Preview deployment stopping request queued.');

        StopApplicationPreview::assertPushed(fn ($action, array $parameters) => $parameters[0]->is($preview));
        StopApplication::assertNotPushed();
    });

    test('returns 404 when the preview does not exist', function () {
        $this->withToken($this->token)
            ->postJson("/api/v1/applications/{$this->application->uuid}/stop?pr=42")
            ->assertNotFound();

        StopApplicationPreview::assertNotPushed();
        StopApplication::assertNotPushed();
    });
});

test('rejects an invalid pull_request_id', function (string $endpoint, mixed $pullRequestId) {
    $this->withToken($this->token)
        ->postJson("/api/v1/applications/{$this->application->uuid}/{$endpoint}", ['pull_request_id' => $pullRequestId])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pull_request_id');

    expect(ApplicationDeploymentQueue::count())->toBe(0)
        ->and($this->application->previews()->count())->toBe(0);
})->with(['start', 'restart', 'stop'])->with([
    'negative' => -1,
    'not a number' => 'abc',
    'too large' => 2147483648,
]);
