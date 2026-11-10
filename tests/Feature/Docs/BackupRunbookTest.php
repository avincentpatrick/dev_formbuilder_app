<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M216 (`R-5f180437`, `R-4bda047a`, `D212`): the runbook can rebuild the nightly backup, and says what it protects.
|--------------------------------------------------------------------------
| Until `M216` the task's script lived only on the box, so a rebuilt server would have had no backup, and §5 prescribed a
| recovery that cannot work: PostgreSQL's manual says a `pg_dump` "cannot be used as part of a continuous-archiving
| solution". The script itself cannot run here (Windows PowerShell on the box); its exit codes were forced on the host
| against throwaway folders. These cases pin what a later edit could quietly undo.
*/

function backupRunbook(): string
{
    return (string) file_get_contents(base_path('docs/deployment-infrastructure.md'));
}

/** The text from one heading up to the next, so a phrase elsewhere in the 900-line runbook cannot satisfy a case. */
function backupRunbookSection(string $heading, string $next): string
{
    $runbook = backupRunbook();
    $start = strpos($runbook, $heading);
    expect($start)->not->toBeFalse();
    $end = strpos($runbook, $next, (int) $start + strlen($heading));
    expect($end)->not->toBeFalse();

    return substr($runbook, (int) $start, (int) $end - (int) $start);
}

function nightlyBackupScript(): string
{
    return (string) file_get_contents(base_path('scripts/nightly-backup.ps1'));
}

it('keeps the nightly backup script in plain ASCII, which is what Windows PowerShell 5.1 reads correctly', function (): void {
    expect(nightlyBackupScript())->toContain('deploy:backup')
        ->and(preg_match('/[^\x00-\x7F]/', nightlyBackupScript()))->toBe(0);
});

it('never lets a night with no copy off the box read as a backup that worked', function (): void {
    $script = nightlyBackupScript();

    expect($script)->toContain('Stop-Backup 3 "no target is set')
        ->and($script)->toContain('Stop-Backup 2 "the target $OffBoxDir cannot be reached')
        ->and($script)->toContain('did not match');
});

it('never creates the target, which is how a missing drive would pass for a working backup', function (): void {
    expect(nightlyBackupScript())->not->toMatch('/New-Item/i');
});

it('sets the backup up in first boot, before automatic deploys, and names the task and its results', function (): void {
    $firstBoot = backupRunbookSection('### 8.2 First boot', '### 8.3 ');
    $step = strpos($firstBoot, 'scripts/nightly-backup.ps1');
    $deploys = strpos($firstBoot, 'Turn on automatic deploys, last');

    expect($step)->not->toBeFalse()
        ->and($deploys)->not->toBeFalse()
        ->and($step)->toBeLessThan($deploys)
        ->and($firstBoot)->toContain("-TaskName 'meridian-test-db-backup'")
        ->and($firstBoot)->toContain('`3` no target is set');
});

it('says WAL needs a physical base backup, and no longer promises to replay it onto a dump', function (): void {
    $backups = backupRunbookSection('## 5. PostgreSQL Backup', '## 6. Queue');

    expect($backups)->toContain('pg_basebackup')
        ->and($backups)->toContain('cannot be that base')
        ->and($backups)->not->toContain('latest clean dump + replay WAL');
});

it('says what binds the testing site, and that it can lose up to a day of data', function (): void {
    $backups = backupRunbookSection('## 5. PostgreSQL Backup', '## 6. Queue');

    expect($backups)->toContain('**The testing site\'s answer (`D212`):**')
        ->and($backups)->toContain('it can lose up to a day of data');
});

it('no longer ends the PostgreSQL step on a bare instruction to configure archiving', function (): void {
    expect(backupRunbook())->not->toContain('Configure `archive_mode`/`archive_command` (§5).');
});
