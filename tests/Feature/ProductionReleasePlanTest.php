<?php

namespace Tests\Feature;

use App\Models\MissionTask;
use App\Services\ProductionReleasePlan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProductionReleasePlanTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_task_plan_preserves_main_changes_and_excludes_other_staging_changes_without_touching_the_checkout(): void
    {
        [$task, $repository, $main, $staging] = $this->fixture();
        file_put_contents($repository.'/main-only.txt', 'unfinished staged work');
        $this->git($repository, ['add', 'main-only.txt']);
        file_put_contents($repository.'/main-only.txt', 'unfinished working copy');
        file_put_contents($repository.'/untracked.txt', 'keep me');
        $index = $this->git($repository, ['write-tree']);
        $status = $this->git($repository, ['status', '--porcelain']);

        $plan = app(ProductionReleasePlan::class)->create($task, $repository, $main, $staging, 'task', ['clock.txt']);

        $this->assertSame($main, $this->git($repository, ['show', '-s', '--format=%P', $plan['release_revision']]));
        $this->assertSame(['clock.txt', 'heading.txt', 'locale.txt'], array_column($plan['changed_paths'], 'path'));
        $this->assertSame('Available Languages', $this->git($repository, ['show', $plan['release_revision'].':heading.txt']));
        $this->assertSame('fixed clock', $this->git($repository, ['show', $plan['release_revision'].':clock.txt']));
        $locale = $this->git($repository, ['show', $plan['release_revision'].':locale.txt']);
        $this->assertStringContainsString('main translation', $locale);
        $this->assertStringContainsString('Available Languages = Idiomas disponibles', $locale);
        $this->assertStringNotContainsString('unrelated staging translation', $locale);
        $this->assertSame('original', $this->git($repository, ['show', $plan['release_revision'].':staging-only.txt']));
        $this->assertSame($index, $this->git($repository, ['write-tree']));
        $this->assertSame($status, $this->git($repository, ['status', '--porcelain']));
        $this->assertSame('unfinished working copy', file_get_contents($repository.'/main-only.txt'));
        $this->assertSame('keep me', file_get_contents($repository.'/untracked.txt'));
        app(ProductionReleasePlan::class)->validate($task, $repository, $plan, $plan['hash']);
    }

    public function test_full_staging_plan_preserves_the_exact_tree_with_deletions_modes_symlinks_and_binary_files(): void
    {
        [$task, $repository, $main, $staging] = $this->fixture();

        $plan = app(ProductionReleasePlan::class)->create($task, $repository, $main, $staging, 'staging', []);

        $this->assertSame($this->git($repository, ['rev-parse', $staging.'^{tree}']), $plan['tree_revision']);
        $this->assertSame($main, $this->git($repository, ['show', '-s', '--format=%P', $plan['release_revision']]));
        $paths = array_column($plan['changed_paths'], 'status', 'path');
        $this->assertSame('D', $paths['obsolete.txt']);
        $this->assertSame('A', $paths['asset.bin']);
        $this->assertSame('A', $paths['heading-link']);
        $this->assertSame('M', $paths['run.sh']);
        $this->assertStringStartsWith('100755', $this->git($repository, ['ls-tree', $plan['release_revision'], 'run.sh']));
        $this->assertStringStartsWith('120000', $this->git($repository, ['ls-tree', $plan['release_revision'], 'heading-link']));
        $this->assertSame($this->git($repository, ['rev-parse', $staging.':asset.bin']), $this->git($repository, ['rev-parse', $plan['release_revision'].':asset.bin']));
    }

    public function test_a_prerequisite_path_with_wildcards_selects_only_that_literal_filename(): void
    {
        [$task, $repository, $main, $staging] = $this->fixture();

        $plan = app(ProductionReleasePlan::class)->create($task, $repository, $main, $staging, 'task', ['fix*.txt']);

        $this->assertSame(['fix*.txt', 'heading.txt', 'locale.txt'], array_column($plan['changed_paths'], 'path'));
        $this->assertSame('updated literal prerequisite', $this->git($repository, ['show', $plan['release_revision'].':fix*.txt']));
        $this->assertSame('original other', $this->git($repository, ['show', $plan['release_revision'].':fix-extra.txt']));
    }

    public function test_a_path_already_changed_by_the_task_cannot_import_unreviewed_staging_edits(): void
    {
        [$task, $repository, $main, $staging] = $this->fixture();
        $this->expectException(ValidationException::class);

        app(ProductionReleasePlan::class)->create($task, $repository, $main, $staging, 'task', ['locale.txt']);
    }

    public function test_repeated_plans_reuse_the_same_release_commit(): void
    {
        [$task, $repository, $main, $staging] = $this->fixture();
        $service = app(ProductionReleasePlan::class);

        $first = $service->create($task, $repository, $main, $staging, 'task', ['clock.txt']);
        $second = $service->create($task, $repository, $main, $staging, 'task', ['clock.txt']);

        $this->assertSame($first['release_revision'], $second['release_revision']);
        $this->assertSame($first['hash'], $second['hash']);
    }

    public function test_modified_plan_scope_is_rejected(): void
    {
        [$task, $repository, $main, $staging] = $this->fixture();
        $service = app(ProductionReleasePlan::class);
        $plan = $service->create($task, $repository, $main, $staging, 'task', []);
        $plan['changed_paths'] = [];
        $this->expectException(ValidationException::class);

        $service->validate($task, $repository, $plan, $plan['hash']);
    }

    public function test_repointing_the_release_branch_invalidates_the_plan(): void
    {
        [$task, $repository, $main, $staging] = $this->fixture();
        $service = app(ProductionReleasePlan::class);
        $plan = $service->create($task, $repository, $main, $staging, 'task', []);
        $this->git($repository, ['update-ref', 'refs/heads/'.$plan['branch'], $staging]);
        $this->expectException(ValidationException::class);

        $service->validate($task, $repository, $plan, $plan['hash']);
    }

    public function test_conflicting_task_patch_fails_without_modifying_main_or_its_index(): void
    {
        [$task, $repository, , $staging] = $this->fixture();
        file_put_contents($repository.'/heading.txt', "Different production heading\n");
        $this->git($repository, ['add', 'heading.txt']);
        $this->git($repository, ['commit', '-m', 'Change the same production line']);
        $main = $this->git($repository, ['rev-parse', 'HEAD']);
        $index = $this->git($repository, ['write-tree']);

        try {
            app(ProductionReleasePlan::class)->create($task, $repository, $main, $staging, 'task', []);
            $this->fail('A conflicting task patch must be rejected.');
        } catch (ValidationException) {
            $this->assertSame($main, $this->git($repository, ['rev-parse', 'HEAD']));
            $this->assertSame($index, $this->git($repository, ['write-tree']));
            $this->assertSame('', $this->git($repository, ['status', '--porcelain']));
        }
    }

    /** @return array{MissionTask, string, string, string} */
    private function fixture(): array
    {
        $root = sys_get_temp_dir().'/mission-production-plan-'.Str::uuid();
        $this->directories[] = $root;
        $repository = $root.'/repository';
        mkdir($repository, 0700, true);
        config(['mission.production_release_root' => $root.'/plans']);
        $this->git($repository, ['init', '-b', 'main']);
        $this->git($repository, ['config', 'user.name', 'Release fixture']);
        $this->git($repository, ['config', 'user.email', 'release@example.test']);
        $locale = array_map(fn (int $line): string => 'translation '.$line, range(1, 40));
        $files = ['heading.txt' => "Where will your voice take you?\n", 'locale.txt' => implode("\n", $locale)."\n", 'clock.txt' => "expired clock\n", 'main-only.txt' => "original\n", 'staging-only.txt' => "original\n", 'obsolete.txt' => "remove later\n", 'run.sh' => "#!/bin/sh\nexit 0\n", 'fix*.txt' => "original literal\n", 'fix-extra.txt' => "original other\n"];
        foreach ($files as $path => $content) {
            file_put_contents($repository.'/'.$path, $content);
        }
        $this->git($repository, ['add', '.']);
        $this->git($repository, ['commit', '-m', 'Common source']);
        $this->git($repository, ['checkout', '-b', 'staging']);
        $locale[39] = 'unrelated staging translation';
        file_put_contents($repository.'/locale.txt', implode("\n", $locale)."\n");
        file_put_contents($repository.'/clock.txt', "fixed clock\n");
        file_put_contents($repository.'/staging-only.txt', "pending unrelated work\n");
        file_put_contents($repository.'/asset.bin', "\0\1\2binary\0\xff");
        file_put_contents($repository.'/fix*.txt', "updated literal prerequisite\n");
        file_put_contents($repository.'/fix-extra.txt', "unapproved glob match\n");
        unlink($repository.'/obsolete.txt');
        chmod($repository.'/run.sh', 0755);
        symlink('heading.txt', $repository.'/heading-link');
        $this->git($repository, ['add', '.']);
        $this->git($repository, ['commit', '-m', 'Other staging work and prerequisite']);
        $base = $this->git($repository, ['rev-parse', 'HEAD']);
        file_put_contents($repository.'/heading.txt', "Available Languages\n");
        array_splice($locale, 20, 0, ['Available Languages = Idiomas disponibles']);
        file_put_contents($repository.'/locale.txt', implode("\n", $locale)."\n");
        $this->git($repository, ['add', '.']);
        $this->git($repository, ['commit', '-m', 'Reviewed task']);
        $revision = $this->git($repository, ['rev-parse', 'HEAD']);
        $this->git($repository, ['checkout', 'main']);
        $mainLocale = explode("\n", trim($files['locale.txt']));
        $mainLocale[0] = 'main translation';
        file_put_contents($repository.'/locale.txt', implode("\n", $mainLocale)."\n");
        file_put_contents($repository.'/main-only.txt', "new production work\n");
        $this->git($repository, ['add', '.']);
        $this->git($repository, ['commit', '-m', 'Independent production work']);
        $main = $this->git($repository, ['rev-parse', 'HEAD']);
        $task = new MissionTask;
        $task->forceFill(['id' => 5, 'title' => 'Available Languages', 'base_revision' => $base, 'revision' => $revision]);

        return [$task, $repository, $main, $revision];
    }

    /** @param list<string> $arguments */
    private function git(string $directory, array $arguments): string
    {
        $process = new Process(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', $directory, ...$arguments]);
        $process->setTimeout(15);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
