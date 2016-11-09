<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Util\RelationshipsUtil;
use Platformsh\Cli\Util\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class EnvironmentSqlSizeCommand extends CommandBase {

    protected function configure() {
        $this
            ->setName('environment:sql-size')
            ->setAliases(['sqls'])
            ->setDescription('Estimate the disk usage of a database');
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        Table::addFormatOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        // Boilerplate.
        $this->validateInput($input);

        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($this->selectApp($input));


        /** @var ShellHelper $shellHelper */
        $shellHelper = $this->getHelper('shell');
        $bufferedOutput = new BufferedOutput();
        $shellHelper->setOutput($bufferedOutput);

        // Get and parse app config.
        $args = ['ssh', $sshUrl, 'echo $' . self::$config->get('service.env_prefix') . 'APPLICATION'];
        $result = $shellHelper->execute($args, NULL, TRUE);
        $appConfig = json_decode(base64_decode($result), TRUE);
        $databaseService = $appConfig['relationships']['database'];
        list($dbServiceName, $dbServiceType) = explode(":", $databaseService);

        // Load services yaml.
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }
        $services = Yaml::parse(file_get_contents($projectRoot . '/.platform/services.yaml'));
        $allocatedDisk = $services[$dbServiceName]['disk'];

        $util = new RelationshipsUtil($this->stdErr);
        $database = $util->chooseDatabase($sshUrl, $input);
        if (empty($database)) {
            return 1;
        }

        $command = ['ssh'];
        // Switch on pseudo-tty allocation when there is a local tty.
        if ($this->isTerminal($output)) {
            $command[] = '-t';
        }
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $command[] = '-vv';
        }
        elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $command[] = '-v';
        }
        elseif ($output->getVerbosity() <= OutputInterface::VERBOSITY_VERBOSE) {
            $command[] = '-q';
        }
        $command[] = $sshUrl;
        switch ($database['scheme']) {
            case 'pgsql':
                $command[] = $this->psqlQuery($database);
                $result = $shellHelper->execute($command);
                $resultArr = explode(PHP_EOL, $result);
                $estimatedUsage = array_sum($resultArr) / 1048576;
                break;
            default:
                $command[] = $this->mysqlQuery($database);
                $estimatedUsage = $shellHelper->execute($command);
                break;
        }

        $percentsUsed = $estimatedUsage * 100 / $allocatedDisk;

        $table = new Table($input, $output);
        $propertyNames = [
            'max',
            'used',
            'percent_used',
        ];
        $machineReadable = $table->formatIsMachineReadable();
        $values = [
            (int) $allocatedDisk . ($machineReadable ? '' : 'MB'),
            (int) $estimatedUsage . ($machineReadable ? '' : 'MB'),
            (int) $percentsUsed . '%',
        ];

        $this->stdErr->writeln('Estimated disk usage for the database: <comment>' . $dbServiceName . '</comment>');

        $table->renderSimple($values, $propertyNames);

        return 0;
    }


    private function psqlQuery($database) {
        // I couldn't find a way to run the SUM directly in the database query...
        $query = "
          SELECT
            sum(pg_relation_size(pg_class.oid))::bigint AS size
          FROM pg_class
          LEFT OUTER JOIN pg_namespace ON (pg_namespace.oid = pg_class.relnamespace)
          GROUP BY pg_class.relkind, nspname
          ORDER BY sum(pg_relation_size(pg_class.oid)) DESC;
        ";

        return "psql --echo-hidden -t --no-align postgresql://{$database['username']}:{$database['password']}@{$database['host']}/{$database['path']} -c \"$query\" 2>&1";
    }

    private function mysqlQuery($database) {
        $query = "
        SELECT 
          (SUM(data_length+index_length+data_free) + (COUNT(*) * 300 * 1024))/1048576+150 AS estimated_actual_disk_usage
        FROM information_schema.tables
        ";
        $params = '--no-auto-rehash --raw --skip-column-names';

        return "mysql $params --database={$database['path']} --host={$database['host']} --port={$database['port']} --user={$database['username']} --password={$database['password']} --execute \"$query\" 2>&1";
    }
}
