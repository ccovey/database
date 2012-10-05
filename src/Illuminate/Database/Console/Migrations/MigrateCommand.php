<?php namespace Illuminate\Database\Console\Migrations;

class MigrateCommand extends BasicMigrationCommand {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'migrate';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Run the database migrations';

	/**
	 * Execute the console command.
	 *
	 * @return void
	 */
	protected function fire()
	{
		$package = $this->input->getArgument('package');

		$path = $this->getPackageMigrationPath($package);

		$this->migrator->runMigrations($this->output, $package, $path, $pretend);
	}

}