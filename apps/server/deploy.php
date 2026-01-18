<?php

namespace Deployer;

require 'recipe/symfony.php';

// =============================================================================
// Application Configuration
// =============================================================================

set('application', 'errata');
set('repository', 'git@github.com:cedricziel/errata.git');
set('branch', 'main');

// Number of releases to keep
set('keep_releases', 5);

// Shared files and directories (persist between releases)
set('shared_files', ['.env.local']);
set('shared_dirs', [
    'var/log',
    'var/data',    // SQLite database if used
    'storage',     // Parquet files
]);

// Writable directories
set('writable_dirs', [
    'var/cache',
    'var/log',
    'var/data',
    'storage',
]);
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0775');

// PHP binary path
set('bin/php', '/usr/bin/php8.5');

// Composer options
set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --optimize-autoloader --no-dev');

// =============================================================================
// Host Configuration
// =============================================================================
// Note: Update these IPs after running `terraform output deployer_hosts_config`
// Example hosts are shown below - replace with actual IPs from Terraform output

// Web servers
host('web-1')
    ->setHostname('YOUR_WEB_1_IP')
    ->set('labels', ['stage' => 'production', 'role' => 'web'])
    ->set('deploy_path', '/var/www/errata')
    ->set('remote_user', 'deploy')
    ->set('http_user', 'www-data');

// Uncomment and update when scaling web servers
// host('web-2')
//     ->setHostname('YOUR_WEB_2_IP')
//     ->set('labels', ['stage' => 'production', 'role' => 'web'])
//     ->set('deploy_path', '/var/www/errata')
//     ->set('remote_user', 'deploy')
//     ->set('http_user', 'www-data');

// Worker servers
host('worker-1')
    ->setHostname('YOUR_WORKER_1_IP')
    ->set('labels', ['stage' => 'production', 'role' => 'worker'])
    ->set('deploy_path', '/var/www/errata')
    ->set('remote_user', 'deploy');

// Uncomment and update when scaling workers
// host('worker-2')
//     ->setHostname('YOUR_WORKER_2_IP')
//     ->set('labels', ['stage' => 'production', 'role' => 'worker'])
//     ->set('deploy_path', '/var/www/errata')
//     ->set('remote_user', 'deploy');

// =============================================================================
// Custom Tasks
// =============================================================================

// Clear and warm up the cache on web servers
task('deploy:cache:warmup', function () {
    run('{{bin/php}} {{bin/console}} cache:warmup --env=prod');
})->select('role=web');

// Run database migrations (only once, on first web server)
task('database:migrate', function () {
    run('{{bin/php}} {{bin/console}} doctrine:migrations:migrate --no-interaction --allow-no-migration');
})->select('role=web')->once();

// Reload PHP-FPM on web servers
task('deploy:php-fpm:reload', function () {
    run('sudo systemctl reload php8.5-fpm');
})->select('role=web');

// Restart Messenger workers on worker servers
task('worker:restart', function () {
    run('sudo supervisorctl restart errata-worker:*');
})->select('role=worker');

// Health check
task('deploy:healthcheck', function () {
    $domain = get('domain', 'localhost');
    run("curl -sf http://localhost/health || echo 'Health check endpoint not responding'");
})->select('role=web');

// =============================================================================
// Deployment Flow
// =============================================================================

// After symlink is created
after('deploy:symlink', 'database:migrate');
after('deploy:symlink', 'deploy:php-fpm:reload');
after('deploy:symlink', 'worker:restart');

// After deployment is complete
after('deploy', 'deploy:healthcheck');

// On failure, unlock
after('deploy:failed', 'deploy:unlock');

// =============================================================================
// Additional Useful Tasks
// =============================================================================

// SSH into a production server
task('ssh', function () {
    $host = currentHost();
    runLocally("ssh -t {$host->getRemoteUser()}@{$host->getHostname()}");
})->desc('Connect to host via SSH');

// View application logs
task('logs', function () {
    run('tail -f {{deploy_path}}/shared/var/log/prod.log');
})->desc('Tail production logs');

// View worker logs
task('logs:worker', function () {
    run('tail -f {{deploy_path}}/shared/var/log/worker_*.log');
})->select('role=worker')->desc('Tail worker logs');

// Show Messenger queue status
task('messenger:status', function () {
    run('{{bin/php}} {{bin/console}} messenger:stats');
})->select('role=worker')->desc('Show Messenger queue status');

// Consume failed messages
task('messenger:retry', function () {
    run('{{bin/php}} {{bin/console}} messenger:failed:retry --all');
})->select('role=worker')->desc('Retry all failed messages');

// Clear failed messages
task('messenger:clear-failed', function () {
    run('{{bin/php}} {{bin/console}} messenger:failed:remove --all');
})->select('role=worker')->desc('Clear all failed messages');

// Database backup
task('db:backup', function () {
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = "{{deploy_path}}/shared/var/data/backup_{$timestamp}.sql";
    run("pg_dump -h 10.0.1.10 -U errata errata > {$backupFile}");
    writeln("<info>Backup created: {$backupFile}</info>");
})->once()->desc('Create database backup');

// =============================================================================
// Deployment Hooks
// =============================================================================

// Before deployment starts
before('deploy', function () {
    writeln('<info>Starting deployment to Errata production servers...</info>');
});

// After successful deployment
after('deploy:success', function () {
    writeln('<info>Deployment completed successfully!</info>');
    writeln('<comment>Run `dep releases` to see all releases</comment>');
    writeln('<comment>Run `dep rollback` to rollback if needed</comment>');
});
