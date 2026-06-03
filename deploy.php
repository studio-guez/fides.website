<?php

namespace Deployer;

use Symfony\Component\Console\Input\InputOption;

require 'recipe/statamic.php';

require_once __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

function deployEnv(string $key, ?string $default = null): string
{
    $value = $_ENV[$key] ?? getenv($key);

    if (($value === false || $value === null || $value === '') && $default === null) {
        throw new \RuntimeException("Missing required environment variable: {$key}");
    }

    return (string) (($value === false || $value === null || $value === '') ? $default : $value);
}

// Config

set('repository', deployEnv('DEPLOY_REPOSITORY'));

set('bin/php', deployEnv('DEPLOY_BIN_PHP', '/opt/php8.4/bin/php'));
set('bin/composer', deployEnv('DEPLOY_BIN_COMPOSER', '/opt/php8.4/bin/composer'));

option('with-content', 'c', InputOption::VALUE_NONE, 'Deploy with content');
option('content-path', null, InputOption::VALUE_OPTIONAL, 'Only deploy a specific subfolder within content (e.g. collections/blog)');

add('shared_files', [
    'database/database.sqlite',
    'public/robots.txt',
    'public/.htaccess',
]);
add('shared_dirs', [
    'content',
    'users'
]);
add('writable_dirs', []);

// Hosts
host('dev')
    ->set('hostname', deployEnv('DEPLOY_HOSTNAME'))
    ->set('remote_user', deployEnv('DEPLOY_REMOTE_USER'))
    ->set('http_user', deployEnv('DEPLOY_HTTP_USER'))
    ->set('deploy_path', deployEnv('DEPLOY_PATH'))
    ->set('branch', deployEnv('DEPLOY_BRANCH', 'deployer'))
    ->set('keep_releases', (int) deployEnv('DEPLOY_KEEP_RELEASES', '2'));


// Hooks

after('deploy:failed', 'deploy:unlock');

// Copy robots.txt and .htaccess to shared if they don't exist
task('deploy:shared_public', function () {
    $sharedDatabasePath = '{{deploy_path}}/shared/database';
    run("mkdir -p {$sharedDatabasePath}");

    if (!test("[ -f {$sharedDatabasePath}/database.sqlite ]")) {
        if (test('[ -f {{release_path}}/database/database.sqlite ]')) {
            run("cp {{release_path}}/database/database.sqlite {$sharedDatabasePath}/database.sqlite");
            writeln('✅ database.sqlite copied to shared folder');
        } else {
            run("touch {$sharedDatabasePath}/database.sqlite");
            writeln('✅ Created shared database.sqlite file');
        }
    }

    $sharedPath = '{{deploy_path}}/shared/public';
    run("mkdir -p {$sharedPath}");

    if (!test("[ -f {$sharedPath}/robots.txt ]")) {
        run("cp {{release_path}}/public/robots.txt {$sharedPath}/robots.txt");
        writeln('✅ robots.txt copied to shared folder');
    }

    if (!test("[ -f {$sharedPath}/.htaccess ]") && test("[ -f {{release_path}}/public/.htaccess ]")) {
        run("cp {{release_path}}/public/.htaccess {$sharedPath}/.htaccess");
        writeln('✅ .htaccess copied to shared folder');
    }

    $usersPath = '{{deploy_path}}/shared/users';
    if (!test("[ -d {$usersPath} ]")) {
        run("mkdir -p {$usersPath}");
        writeln('✅ Created shared/users directory');
    }
});

before('deploy:shared', 'deploy:shared_public');

// Deploy content when the --with-content|-c flag is passed
task('deploy:update_content', function () {
    if (input()->getOption('with-content')) {
        $contentPath = input()->getOption('content-path');

        if ($contentPath) {
            $contentPath = trim($contentPath, '/');
            writeln("<fg=yellow;options=bold>⚠ WARNING: You are deploying content/{$contentPath}! Ensure this is intentional.</>");
        } else {
            writeln('<fg=yellow;options=bold>⚠ WARNING: You are deploying with content! Ensure this is intentional.</>');
        }

        $confirmation = ask('Type "yes" to confirm content deployment:');

        if ($confirmation !== 'yes') {
            writeln('<fg=red;options=bold>❌ Content deployment aborted.</>');
            return;
        }

        if ($contentPath) {
            writeln("✅ Deploying content/{$contentPath}...");
            run("mkdir -p {{deploy_path}}/shared/content/{$contentPath}");
            run("rm -fr {{deploy_path}}/shared/content/{$contentPath}/*");
            run("cp -Rf {{release_path}}/content/{$contentPath}/* {{deploy_path}}/shared/content/{$contentPath}/");
        } else {
            writeln('✅ Deploying content...');
            run('rm -fr {{deploy_path}}/shared/content/*');
            run('cp -Rf {{release_path}}/content/* {{deploy_path}}/shared/content/');
        }
    } else {
        writeln('Skipping content deployment...');
        run('for dir in {{release_path}}/content/*/; do name=$(basename "$dir"); mkdir -p {{deploy_path}}/shared/content/"$name"; find "$dir" -maxdepth 1 -name "*.yaml" -exec cp -f {} {{deploy_path}}/shared/content/"$name"/ \;; done');
    }
});

after('deploy:update_code', 'deploy:update_content');
