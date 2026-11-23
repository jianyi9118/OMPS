<?php
namespace Omps\Database;

/**
 * Forward-only migrations. Each file in database/migrations returns
 *   array('up' => array('CREATE TABLE ...', ...))
 * Files run once, in file-name order; ran files are recorded in `migrations`.
 * Re-running `migrate` never touches existing data.
 */
class Migrator
{
    /** @var string */
    private $dir;

    public function __construct($dir = null)
    {
        $this->dir = $dir === null ? OMPS_ROOT . '/database/migrations' : $dir;
    }

    public static function tableOptions()
    {
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    public function ensureTable()
    {
        DB::exec('CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `migration` VARCHAR(191) CHARACTER SET ascii NOT NULL,
            `batch` INT UNSIGNED NOT NULL,
            `ran_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_migration` (`migration`)
        ) ' . self::tableOptions());
    }

    /** @return string[] file base names */
    public function files()
    {
        $files = glob($this->dir . '/*.php');
        $files = $files === false ? array() : $files;
        $names = array();
        foreach ($files as $f) {
            $names[] = basename($f, '.php');
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /** Read-only (works with the day-to-day account, which cannot create tables). */
    public function ran()
    {
        if (!DB::select("SHOW TABLES LIKE 'migrations'")) {
            return array();
        }
        return DB::table('migrations')->orderBy('id')->pluck('migration');
    }

    public function pending()
    {
        return array_values(array_diff($this->files(), $this->ran()));
    }

    /**
     * @param callable|null $out progress callback(string)
     * @return string[] migrations that ran
     */
    public function migrate($out = null)
    {
        $this->ensureTable();
        $pending = $this->pending();
        if (!$pending) {
            return array();
        }
        $batch = (int) DB::value('SELECT COALESCE(MAX(batch), 0) FROM migrations') + 1;
        foreach ($pending as $name) {
            $def = require $this->dir . '/' . $name . '.php';
            if (!is_array($def) || !isset($def['up']) || !is_array($def['up'])) {
                throw new \RuntimeException('Migration ' . $name . ' must return array(\'up\' => array(...))');
            }
            foreach ($def['up'] as $sql) {
                DB::exec(str_replace('{options}', self::tableOptions(), $sql));
            }
            DB::table('migrations')->insert(array('migration' => $name, 'batch' => $batch, 'ran_at' => now()));
            if ($out !== null) {
                call_user_func($out, $name);
            }
        }
        return $pending;
    }

    /** Drop every table in the current database. Only for the testing database. */
    public function dropAll()
    {
        DB::exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (DB::select('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'') as $row) {
            $vals = array_values($row);
            DB::exec('DROP TABLE `' . str_replace('`', '', (string) $vals[0]) . '`');
        }
        DB::exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
