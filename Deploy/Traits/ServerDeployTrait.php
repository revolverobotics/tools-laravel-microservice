<?php

namespace App\Submodules\ToolsLaravelMicroservice\Deploy\Traits;

use SSH;
use App\Submodules\ToolsLaravelMicroservice\Deploy\GitManager;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

trait ServerDeployTrait
{
    protected $dbCredentials;

    protected $dbBackup;

    protected $dbMigrations = false;

    protected function checkEnvFiles()
    {
        $this->out(
            "Beginning server deployment on ".
            "[<cyan>{$this->git->remote}</cyan>]...",
            'comment',
            "\n "
        );
        $this->out('');

        $this->checkEnvFile('.env');
        $this->checkEnvFile('.env.testing');
    }

    protected function checkEnvFile($which)
    {
        $this->out(
            "Checking <white>{$which}</white> file ".
                "diff between local & remote...",
            'info',
            ' . '
        );

        // First check for file existence:
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'ls -la '.$which
        ];

        SSH::into($this->git->remote)->run($commandArray, function ($line) {
            if (strpos($line, 'cannot access') !== false) {
                $this->outError("Couldn't find {$which} on remote: {$line}");
                throw new \Exception('Aborting.');
            }
        });

        // Now check that files have matching variables:
        $localVars = [];
        $remoteVars = [];

        $localProcess = new Process('cat '.$which);
        $localProcess->setWorkingDirectory(base_path());
        $localProcess->run(function ($type, $buffer) use (&$localVars) {
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                $splitLine = explode("=", $line);
                if (count($splitLine) > 1) {
                    array_push($localVars, $splitLine[0]);
                }
            }
        });

        SSH::into($this->git->remote)->run(
            [
                'cd '.env('REMOTE_WORKTREE'),
                'cat '.$which
            ],
            function ($contents) use (&$remoteVars) {
                $lines = explode("\n", $contents);
                foreach ($lines as $line) {
                    $splitLine = explode("=", $line);
                    if (count($splitLine) > 1) {
                        array_push($remoteVars, $splitLine[0]);
                    }
                }
            }
        );


        $diff = array_diff($localVars, $remoteVars);

        if (count($diff) > 0) {
            $tabulated = [];

            function addToTabulated($array, &$tabulated, $index, $max)
            {
                for ($i = 0; $i < $max; $i++) {
                    if (!isset($array[$i])) {
                        $array[$i] = '';
                    }
                }

                foreach ($array as $key => $value) {
                    if (!isset($tabulated[$key])) {
                        $tabulated[$key] = [];
                    }
                    $tabulated[$key][$index] = $value;
                }
            }

            $max = max([count($localVars), count($remoteVars)]);
            addToTabulated($localVars, $tabulated, 0, $max);
            addToTabulated($remoteVars, $tabulated, 1, $max);
            addToTabulated($diff, $tabulated, 2, $max);

            $this->table(
                ["Local {$which} vars", "Remote {$which} vars", "Diff"],
                $tabulated
            );

            if (!$this->confirm(
                "Local {$which} doesn't match remote. Continue?",
                false
            )) {
                exit;
            }
        }

        $this->out(
            "{$which} match (or skip) confirmed by user\n",
            'line',
            ' ✓ '
        );
    }

    protected function putIntoMaintenanceMode()
    {
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'pwd',
            'php artisan down'
        ];

        $count = 0;

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
            &$count
        ) {
            $count++;
            $line = rtrim($line);

            switch ($count) {
                case 1:
                    $this->out(
                        'Verifying remote directory...',
                        'info',
                        "\n . "
                    );

                    if ($line != env('REMOTE_WORKTREE')) {
                        $this->outError('Remote directory does not match '.
                            'env variable.');
                        throw new \Exception('Aborting.');
                    }

                    $this->out('Remote directory verified.', 'line', ' ✓ ');
                    break;

                case 2:
                    $this->out(
                        'Placing remote app into maintenance mode...',
                        'info',
                        "\n . "
                    );

                    if ($line != 'Application is now in maintenance mode.') {
                        $this->outError('Couldn\'t put remote app into '.
                            'maintenance mode.');
                        throw new \Exception('Aborting.');
                    }

                    $this->out($line, 'line', ' ✓ ');
                    break;
            }
        });
    }

    protected function runDeployCommands()
    {
        $this->verifyCommitAndUpdateDependencies();

        $this->checkForMigrations();

        try {
            if ($this->dbMigrations) {
                $this->getDbCredentials();
                $this->backupDatabase();
                $this->runMigrations();
            } else {
                $this->runUnitTests();
            }
        } catch (\Exception $e) {
            $this->runRollbackCommands();

            try {
                $this->runUnitTests();
            } catch (\Exception $e) {
                $this->outError('Unit tests failed after performing a '.
                    'rollback.  We might be in some deep doo-doo.');
                throw new \Exception('--- RED ALERT ---');
            }
        }

        if ($this->option('docs') == true) {
            $this->generateDocumentation();
        }
    }

    protected function verifyCommitAndUpdateDependencies()
    {
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'git --git-dir='.env('REMOTE_GITDIR').
                ' --work-tree='.env('REMOTE_WORKTREE').
                ' rev-parse --verify HEAD',
            'composer update'
        ];

        $count = 0;

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
            &$count
        ) {
            $count++;
            $line = rtrim($line);

            if ($count < 2) {
                $this->out('Verifying commit...', 'info', "\n . ");

                if ($line != $this->git->getCurrentCommitHash()) {
                    $this->outError('Newly-pushed commit hash does not match.');
                    throw new \Exception('Aborting.');
                }

                $this->out('New commit verified.', 'line', ' ✓ ');
                $this->out('Updating dependencies...', 'info', "\n . ");
                $this->out('');
            } else {
                $this->out($line, 'info', "\t");
            }
        });

        $this->out('Done.', 'line', "\n ✓ ");
    }

    protected function checkForMigrations()
    {
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'php artisan migrate:status',
        ];

        $this->out('Checking for migrations...', 'info', "\n . ");

        $migrationStatus = "";

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
            &$migrationStatus
        ) {
            $migrationStatus .= $line;
        });

        $this->out($migrationStatus, 'line', "\n");

        $migrationStatus = explode("\n", $migrationStatus);

        foreach ($migrationStatus as $migration) {
            $status = substr($migration, 0, 8);
            if (strpos($status, 'N') !== false) {
                $this->dbMigrations = true;
            }
        }

        if ($this->dbMigrations) {
            $this->out('Pending migrations found.', 'comment', ' ✓ ');
        } else {
            $this->out('No pending migrations found.', 'comment', ' ✓ ');
        }
    }

    protected function runUnitTests()
    {
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'vendor/phpunit/phpunit/phpunit --no-coverage'
        ];

        $this->out('Running unit tests...', 'info', "\n . ");

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
            &$count
        ) {
            $count++;
            $line = rtrim($line);
            // $this->out('Backing up database...', 'info', "\n . ");

            $this->out($line, 'line', "\n");

            if (strpos($line, 'FAILURES!') !== false) {
                $this->outError('Unit tests failed.');
                throw new \Exception('Unit tests failed.');
            }
        });

        $this->out('Done.', 'line', ' ✓ ');
    }

    protected function getDbCredentials()
    {
        $dbCredentials = [
            'DB_HOST'     => null,
            'DB_DATABASE' => null,
            'DB_USERNAME' => null,
            'DB_PASSWORD' => null
        ];

        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'cat .env'
        ];

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
            &$dbCredentials
        ) {
            $line = rtrim($line);

            $this->out(
                'Fetching DB credentials for backup',
                'info',
                "\n . "
            );

            $vars = explode("\n", $line);

            foreach ($vars as $var) {
                $env = explode('=', $var);
                if (array_key_exists($env[0], $dbCredentials)) {
                    $dbCredentials[$env[0]] = $env[1];
                }
            }

            foreach ($dbCredentials as $env) {
                if (is_null($env)) {
                    $this->out('Couldn\'t get all SQL '.
                        'credentials. Check remote .env file.', 'error');
                    throw new \Exception('Error.');
                }
            }

            $this->out('Credentials obtained.', 'line', ' ✓ ');
        });

        $this->dbCredentials = $dbCredentials;
    }

    protected function backupDatabase()
    {
        $dbCredentials = $this->dbCredentials;

        $this->dbBackup = $dbCredentials['DB_DATABASE'].
            '_'.$this->pushTime.'.sql';

        $commandArray = [
            'export TERM=vt100',
            "mysqldump -u {$dbCredentials['DB_USERNAME']} ".
            "--password={$dbCredentials['DB_PASSWORD']} ".
            "-h {$dbCredentials['DB_HOST']} ".
            "{$dbCredentials['DB_DATABASE']} ".
            "> /var/tmp/{$this->dbBackup}",
            'ls -l /var/tmp/ | grep "\.sql"'
        ];

        $this->out('Backing up database...', 'info', "\n . ");

        $count = 0;

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
            &$count
        ) {
            $line = rtrim($line);
            // $this->out($line, 'line', "\n");
            $count++;
            if (strpos($line, $this->dbBackup) !== false) {
                $this->out(
                    'Backup verified and is located at: /var/tmp/'.$this->dbBackup,
                    'line',
                    ' ✓ '
                );
            } else {
                $this->out(
                    'Backup couldn\'t be found at /var/tmp/'.$this->dbBackup,
                    'error'
                );

                throw new \Exception(
                    'Backup could not be at /var/tmp/'.$this->dbBackup
                );
            }
        });

        if ($count < 1) {
            $this->outWarning('No output from backup. It may have failed.');
        }

        $this->scpBackup();
    }

    protected function scpBackup()
    {
        $this->out('Downloading backup to local machine...', 'info', "\n . ");

        $server = strtoupper($this->git->remote);

        passthru(
            'scp -i '.env('DEPLOY_KEY').
            ' ec2-user@'.env($server.'_HOST').
            ':/var/tmp/'.$this->dbBackup.' /var/tmp/.'
        );

        exec('ls -l /var/tmp | grep "\.sql"', $localBackupList);
        // $this->out($localBackupList, 'line', "\n");

        $backupFound = false;

        foreach ($localBackupList as $line) {
            if (strpos($line, $this->dbBackup) !== false) {
                $backupFound = true;
            }
        }

        if ($backupFound) {
            $this->out(
                'Backup verified and is located at: /var/tmp/'.$this->dbBackup,
                'line',
                ' ✓ '
            );
        } else {
            $this->outError('Backup couldn\'t be found at /var/tmp/'.
                $this->dbBackup);

            throw new \Exception(
                'Backup could not be at /var/tmp/'.$this->dbBackup
            );
        }
    }

    protected function runMigrations()
    {
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'php artisan migrate --force'
        ];

        $count = 0;

        $this->out('Running database migrations...', 'info', "\n . ");

        try {
            SSH::into($this->git->remote)->run(
                $commandArray,
                function ($line) use (&$count) {
                    $this->out(trim($line));
                    if (strpos($line, 'SQLSTATE') !== false) {
                        throw new \Exception('Failure.');
                    }
                }
            );
        } catch (\Exception $e) {
            $this->outError('Exceptions found when running migrations.');
            throw new \Exception('Failed.');
        }
    }

    protected function generateDocumentation()
    {
        $this->out('Generating API Documentation...', 'info', "\n . ");

        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'apidoc -i app/ -o public/docs/ -t public/apidoc-template/'
        ];

        SSH::into($remote)->run($commandArray);

        $this->out('Done.', 'line', "\n ✓ ");
    }

    protected function takeOutOfMaintenanceMode()
    {
        $this->outputSeparator();

        $this->out(
            'Taking app out of maintenance mode...',
            'comment'
        );
        $this->out('');

        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'php artisan up',
        ];

        $count = 0;

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
            &$count
        ) {
            $count++;
            $line = rtrim($line);

            switch ($count) {
                case 1:
                    $this->out(
                        '...',
                        'info',
                        " . "
                    );

                    if ($line != 'Application is now live.') {
                        $this->outError('Couldn\'t take remote app out '.
                            'of maintenance mode');
                        throw new \Exception('Aborting.');
                    }

                    $this->out($line, 'line', ' ✓ ');
                    break;
            }
        });
    }
}
