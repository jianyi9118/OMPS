<?php
namespace Omps\Database;

use PDO;

/**
 * Database access point. Two connections (design §03):
 *  - 'app'     : day-to-day account (DML only)
 *  - 'migrate' : schema changes, used by the CLI only
 */
class DB
{
    /** @var PDO[] */
    private static $pdo = array();
    /** @var string */
    private static $current = 'app';
    /** @var int */
    private static $txLevel = 0;

    public static function connection($name = null)
    {
        $name = $name === null ? self::$current : $name;
        if (!isset(self::$pdo[$name])) {
            self::$pdo[$name] = self::connect($name);
        }
        return self::$pdo[$name];
    }

    /** Switch the default connection (the CLI uses 'migrate' while migrating). */
    public static function useConnection($name)
    {
        self::$current = $name;
    }

    public static function disconnect()
    {
        self::$pdo = array();
        self::$txLevel = 0;
    }

    private static function connect($name)
    {
        $c = config('database');
        $user = $name === 'migrate' ? $c['migrate_username'] : $c['username'];
        $pass = $name === 'migrate' ? $c['migrate_password'] : $c['password'];
        $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int) $c['port'] . ';dbname=' . $c['database'] . ';charset=' . $c['charset'];
        $pdo = new PDO($dsn, $user, $pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ));
        $pdo->exec("SET NAMES " . $c['charset'] . " COLLATE " . $c['collation']);
        $pdo->exec("SET SESSION sql_mode = " . $pdo->quote($c['sql_mode']));
        return $pdo;
    }

    /** Connect to the server without selecting a database (used to create the database). */
    public static function serverConnection($asMigrate = true)
    {
        $c = config('database');
        $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int) $c['port'] . ';charset=' . $c['charset'];
        return new PDO($dsn, $asMigrate ? $c['migrate_username'] : $c['username'], $asMigrate ? $c['migrate_password'] : $c['password'],
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    }

    public static function table($table)
    {
        return new QueryBuilder($table);
    }

    public static function select($sql, array $params = array())
    {
        $st = self::connection()->prepare($sql);
        $st->execute(array_values($params));
        return $st->fetchAll();
    }

    public static function selectOne($sql, array $params = array())
    {
        $rows = self::select($sql, $params);
        return $rows ? $rows[0] : null;
    }

    public static function value($sql, array $params = array())
    {
        $st = self::connection()->prepare($sql);
        $st->execute(array_values($params));
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function statement($sql, array $params = array())
    {
        $st = self::connection()->prepare($sql);
        $st->execute(array_values($params));
        return $st->rowCount();
    }

    public static function exec($sql)
    {
        return self::connection()->exec($sql);
    }

    public static function lastInsertId()
    {
        return (int) self::connection()->lastInsertId();
    }

    /**
     * Run a callback in a transaction (nested calls join the outer one).
     * @param callable $fn
     */
    public static function transaction($fn)
    {
        $pdo = self::connection();
        if (self::$txLevel === 0) {
            $pdo->beginTransaction();
        }
        self::$txLevel++;
        try {
            $result = call_user_func($fn);
            self::$txLevel--;
            if (self::$txLevel === 0) {
                $pdo->commit();
            }
            return $result;
        } catch (\Exception $e) {
            self::rollbackAll($pdo);
            throw $e;
        } catch (\Throwable $e) {
            self::rollbackAll($pdo);
            throw $e;
        }
    }

    private static function rollbackAll(PDO $pdo)
    {
        self::$txLevel = 0;
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    public static function inTransaction()
    {
        return self::$txLevel > 0;
    }
}
