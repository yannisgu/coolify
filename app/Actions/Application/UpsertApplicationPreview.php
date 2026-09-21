<?php

namespace App\Actions\Application;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use Lorisleiva\Actions\Concerns\AsAction;

class UpsertApplicationPreview
{
    use AsAction;

    public function handle(
        Application $application,
        int $pullRequestId,
        ?string $pullRequestHtmlUrl = null,
        ?string $gitType = null,
        ?string $dockerRegistryImageTag = null,
    ): ?ApplicationPreview {
        $gitType ??= $this->gitType($application);
        $pullRequestHtmlUrl ??= $this->pullRequestUrl($application, $pullRequestId, $gitType);
        $preview = $application->previews()->where('pull_request_id', $pullRequestId)->first();

        if (! $preview) {
            $canCreate = filled($pullRequestHtmlUrl)
                || ($application->build_pack === 'dockerimage' && filled($dockerRegistryImageTag));
            if (! $canCreate) {
                return null;
            }

            $preview = ApplicationPreview::create([
                'application_id' => $application->id,
                'pull_request_id' => $pullRequestId,
                'pull_request_html_url' => $pullRequestHtmlUrl ?? '',
                'git_type' => $gitType,
                'docker_compose_domains' => $application->build_pack === 'dockercompose'
                    ? $application->docker_compose_domains
                    : null,
                'docker_registry_image_tag' => $dockerRegistryImageTag,
            ]);
        } else {
            $updates = [];
            if (blank($preview->git_type) && filled($gitType)) {
                $updates['git_type'] = $gitType;
            }
            if (blank($preview->pull_request_html_url) && filled($pullRequestHtmlUrl)) {
                $updates['pull_request_html_url'] = $pullRequestHtmlUrl;
            }
            if (filled($dockerRegistryImageTag) && $preview->docker_registry_image_tag !== $dockerRegistryImageTag) {
                $updates['docker_registry_image_tag'] = $dockerRegistryImageTag;
            }
            if ($updates !== []) {
                $preview->update($updates);
            }
        }

        if ($application->build_pack === 'dockercompose') {
            $preview->generate_preview_fqdn_compose(generateWithoutApplicationDomain: true);
        } else {
            $preview->generate_preview_fqdn(generateWithoutApplicationDomain: true);
        }

        return $preview;
    }

    private function gitType(Application $application): ?string
    {
        return match ($application->source_type) {
            GithubApp::class => 'github',
            GitlabApp::class => 'gitlab',
            default => null,
        };
    }

    private function pullRequestUrl(Application $application, int $pullRequestId, ?string $gitType): ?string
    {
        $sourceUrl = data_get($application, 'source.html_url');
        if (blank($sourceUrl) || blank($gitType)) {
            return null;
        }

        $repository = str($application->git_repository)->trim('/');
        if ($repository->endsWith('.git')) {
            $repository = $repository->beforeLast('.git');
        }
        $repositoryUrl = rtrim($sourceUrl, '/').'/'.$repository->value();

        return match ($gitType) {
            'github' => "{$repositoryUrl}/pull/{$pullRequestId}",
            'gitlab' => "{$repositoryUrl}/-/merge_requests/{$pullRequestId}",
            default => null,
        };
    }
}
