<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Console\Command;

class MakeService extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'make:service {name}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Create Service';

	/**
	 * Execute the console command.
	 */
	public function handle()
	{
		$name = $this->argument('name');
		$path = app_path("Services/{$name}Service.php");

		if (File::exists($path)) {
			$this->error('Service already exists!');
			return;
		}

		File::ensureDirectoryExists(app_path('Services'));

		File::put($path, "<?php

	namespace App\Services;
	use App\Models\\{$name};

	class {$name}Service
	{
		//
	}
	");

		$this->info("Service {$name} created successfully.");
	}
}