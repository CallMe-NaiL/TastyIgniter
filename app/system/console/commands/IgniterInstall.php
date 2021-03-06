<?php

namespace System\Console\Commands;

use App;
use Config;
use DB;
use Igniter\Flame\Support\ConfigRewrite;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use System\Classes\UpdateManager;

/**
 * Console command to install TastyIgniter.
 * This sets up TastyIgniter for the first time. It will prompt the user for several
 * configuration items, including application URL and database config, and then
 * perform a database migration.
 */
class IgniterInstall extends Command
{
    /**
     * The console command name.
     */
    protected $name = 'igniter:install';

    /**
     * The console command description.
     */
    protected $description = 'Set up TastyIgniter for the first time.';

    /**
     * @var \Igniter\Flame\Support\ConfigRewrite
     */
    protected $configWriter;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();

        $this->configRewrite = new ConfigRewrite;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->alert('INSTALLATION');

        if (
            App::hasDatabase() AND
            !$this->confirm('Application appears to be installed already. Continue anyway?', FALSE)
        ) {
            return;
        }

        $this->line('Enter a new value, or press ENTER for the default');

        $this->rewriteConfigFiles();

        $this->migrateDatabase();

        $this->createDefaultLocation();

        $this->createSuperUser();

        $this->addSystemValues();

        $this->alert('INSTALLATION COMPLETE');
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments()
    {
        return [];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions()
    {
        return [
            ['force', null, InputOption::VALUE_NONE, 'Force install.'],
        ];
    }

    protected function rewriteConfigFiles()
    {
        $this->writeDatabaseConfig();
        $this->writeToConfig('app', ['key' => $this->generateEncryptionKey()]);
    }

    protected function writeDatabaseConfig()
    {
        $config = [];
        $config['host'] = $this->ask('MySQL Host', Config::get('database.connections.mysql.host'));
        $config['port'] = $this->ask('MySQL Port', Config::get('database.connections.mysql.port') ?: FALSE) ?: '';
        $config['database'] = $this->ask('Database Name', Config::get('database.connections.mysql.database'));
        $config['username'] = $this->ask('MySQL Login', Config::get('database.connections.mysql.username'));
        $config['password'] = $this->ask('MySQL Password', Config::get('database.connections.mysql.password') ?: FALSE) ?: '';
        $config['prefix'] = $this->ask('MySQL Table Prefix', Config::get('database.connections.mysql.prefix') ?: FALSE) ?: '';

        $this->writeToConfig('database', ['default' => 'mysql']);

        foreach ($config as $config => $value) {
            $this->writeToConfig('database', ['connections.mysql.'.$config => $value]);
        }
    }

    protected function migrateDatabase()
    {
        $this->line('Migrating application and extensions...');

        DB::purge();

        $manager = UpdateManager::instance()->resetLogs();

        $manager->update();

        foreach ($manager->getLogs() as $note) {
            $this->output->writeln($note);
        }

        $this->line('Done. Migrating application and extensions...');
    }

    protected function createDefaultLocation()
    {
        $siteName = $this->ask('Site Name', 'TastyIgniter');
        $this->writeToConfig('app', ['name' => $siteName]);

        $url = $this->ask('Site URL', Config::get('app.url'));
        $this->writeToConfig('app', ['url' => $url]);

        \Admin\Models\Locations_model::insert(['location_name' => $siteName]);
        $this->line('Location '.$siteName.' created!');
    }

    protected function createSuperUser()
    {
        $name = $this->ask('Admin Name', 'Chef Sam');
        $email = $this->ask('Admin Email', 'admin@domain.tld');
        $username = $this->ask('Admin Username', 'admin');
        $password = $this->ask('Admin Password', '123456');

        $staff = \Admin\Models\Staffs_model::firstOrNew(['staff_email' => $email]);
        $staff->staff_name = $name;
        $staff->staff_group_id = \Admin\Models\Staff_groups_model::first()->staff_group_id;
        $staff->staff_location_id = \Admin\Models\Locations_model::first()->location_id;
        $staff->language_id = \System\Models\Languages_model::first()->language_id;
        $staff->timezone = FALSE;
        $staff->staff_status = TRUE;
        $staff->save();

        $user = \Admin\Models\Users_model::firstOrNew(['username' => $username]);
        $user->staff_id = $staff->staff_id;
        $user->password = $password;
        $user->super_user = TRUE;
        $user->is_activated = TRUE;
        $user->date_activated = \Carbon\Carbon::now();
        $user->save();

        $this->line('Admin user '.$username.' created!');
    }

    protected function addSystemValues()
    {
        params()->set([
            'ti_setup' => 'installed',
            'default_location_id' => \Admin\Models\Locations_model::first()->location_id,
        ]);

        // These parameter are no longer in use
        params()->forget('main_address');

        UpdateManager::instance()->setCoreVersion();
    }

    protected function writeToConfig($file, $values)
    {
        $configFile = $this->getConfigFile($file);

        foreach ($values as $key => $value) {
            Config::set($file.'.'.$key, $value);
        }

        $this->configRewrite->toFile($configFile, $values);
    }

    protected function getConfigFile($name = 'app')
    {
        $env = $this->option('env') ? $this->option('env').'/' : '';
        $path = $this->laravel['path.config']."/{$env}{$name}.php";

        return $path;
    }

    protected function generateEncryptionKey()
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }
}
