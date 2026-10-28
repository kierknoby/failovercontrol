<?php
namespace FreePBX\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class FailovercontrolStatus extends Command
{
    protected function configure(): void
    {
        $this->setName('failovercontrol:status')
            ->setDescription(_('Show persisted and active Failover Control configuration'))
            ->addOption('json', null, InputOption::VALUE_NONE, _('Output JSON'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $statuses = \FreePBX::Failovercontrol()->getControlStatuses();
        } catch (\Throwable $exception) {
            $output->writeln('<error>Unable to inspect Failover Control configuration.</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        } elseif (!$statuses) {
            $output->writeln(_('No Failover Controls configured.'));
        } else {
            $table = new Table($output);
            $table->setHeaders([_('ID'), _('Name'), _('Failover'), _('Status'), _('Detail')]);
            foreach ($statuses as $status) {
                $table->addRow([$status['id'], $status['name'], $status['enabled'], $status['status'], $status['detail']]);
            }
            $table->render();
        }

        foreach ($statuses as $status) {
            if (!in_array($status['status'], ['Control Enabled', 'Control Disabled'], true)) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}