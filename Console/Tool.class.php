<?php
namespace FreePBX\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Tool extends Command {

	protected function configure() {
		$this->setName('frogman:tool')
			->setDescription('Run a Frogman tool from the CLI')
			->addArgument('toolname', InputArgument::OPTIONAL, 'Tool name to execute')
			->addArgument('json_params', InputArgument::OPTIONAL, 'JSON-encoded parameters', '{}');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$freepbx = \FreePBX::Create();
		$frogman = $freepbx->Frogman;

		$toolName = $input->getArgument('toolname');

		// No tool name — list available tools
		if (empty($toolName)) {
			$tools = $frogman->getToolList();
			if (empty($tools)) {
				$output->writeln('<comment>No tools registered.</comment>');
				return 0;
			}
			$output->writeln('<info>Available tools:</info>');
			foreach ($tools as $t) {
				$output->writeln(sprintf('  <comment>%s</comment> — %s', $t['name'], $t['description']));
			}
			return 0;
		}

		// Parse JSON params
		$jsonStr = $input->getArgument('json_params');
		$params = json_decode($jsonStr, true);
		if ($params === null && $jsonStr !== '{}') {
			$output->writeln('<error>Invalid JSON parameters</error>');
			return 1;
		}

		// Run tool (userId 0 = CLI/root context)
		$result = $frogman->runTool($toolName, $params, 0, 'cli');

		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		return $result['status'] === 'success' ? 0 : 1;
	}
}
