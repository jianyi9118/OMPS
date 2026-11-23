<?php
namespace Omps\Tests;

use Omps\Console\CompatCheck;
use Omps\Core\Lang;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Database\Migrator;

/**
 * Kernel units: query builder, validator, translations, compat scanner, migrations.
 */
class CoreTest extends TestCase
{
    public function testQueryBuilderBindsValuesAndRejectsBadIdentifiers()
    {
        $params = array();
        $sql = DB::table('users u')->where('u.username', "x' OR '1'='1")->whereIn('u.id', array(1, 2))->orderBy('u.id', 'DESC')->limit(5)->toSql($params);
        $this->same('SELECT * FROM `users` `u` WHERE `u`.`username` = ? AND `u`.`id` IN (?,?) ORDER BY `u`.`id` DESC LIMIT 5', $sql);
        $this->same(array("x' OR '1'='1", 1, 2), $params);
        $bad = false;
        try {
            DB::table('users')->where('id; DROP TABLE users', 1);
        } catch (\InvalidArgumentException $e) {
            $bad = true;
        }
        $this->ok($bad, 'bad identifier must be rejected');
        $empty = array();
        DB::table('users')->whereIn('id', array())->toSql($empty);
        $this->same(0, DB::table('users')->whereIn('id', array())->count(), 'empty IN matches nothing');
        $guard = false;
        try {
            DB::table('users')->update(array('status' => 'disabled'));
        } catch (\LogicException $e) {
            $guard = true;
        }
        $this->ok($guard, 'UPDATE without WHERE refused');
    }

    public function testLikeSearchEscapesWildcards()
    {
        $id = $this->dept('A%B_C');
        $this->dept('AxxByC');
        $rows = DB::table('departments')->whereLikeAny(array('name'), '%B_')->get();
        $this->same(1, count($rows));
        $this->same($id, (int) $rows[0]['id']);
    }

    public function testValidator()
    {
        Lang::setLocale('en-US');
        $v = Validator::check(array('n' => ' 12 ', 'b' => 'true', 's' => 'abc'), array('n' => 'required|int', 'b' => 'bool', 's' => 'string|max:5'));
        $this->same(12, $v['n']);
        $this->same(1, $v['b']);
        try {
            Validator::check(array('name' => '', 'd' => '2026-02-30', 'g' => 'x'), array('name' => 'required|string', 'd' => 'date', 'g' => 'in:male,female'), array('name' => 'staff.field.name'));
            $this->ok(false, 'should fail');
        } catch (ValidationException $e) {
            $this->same('Name is required', $e->errors['name']);
            $this->ok(isset($e->errors['d']) && isset($e->errors['g']));
        }
    }

    public function testTranslationsAndParams()
    {
        $this->same('王晓，您好', Lang::get('home.welcome', array('name' => '王晓'), 'zh-CN'));
        $this->same('Hello, 王晓', Lang::get('home.welcome', array('name' => '王晓'), 'en-US'));
        $this->same('Name is required', Lang::get('validation.required', array('field' => array('t' => 'staff.field.name')), 'en-US'));
        $this->same('⟦no.such.key⟧', Lang::get('no.such.key', array(), 'en-US'), 'missing key never falls back to another language');
        $this->same(array_keys(Lang::loadPack('zh-CN')), array_keys(Lang::loadPack('en-US')), 'packs have the same keys in the same order');
    }

    public function testCompatScannerFindsNewAndRemovedSyntax()
    {
        $code = '<?php $a ??= 1; $f = fn($x) => $x; $s = $str{0}; each($arr); if ($a == 1) {} class X { private int $y; public $ok; } function nul(array $a = null) {}';
        $msgs = implode("\n", array_map(function ($p) { return $p[1]; }, CompatCheck::scan($code)));
        foreach (array('??=', 'arrow function', 'string offset', 'each()', 'loose comparison', 'typed property', 'implicitly nullable') as $needle) {
            $this->contains($needle, $msgs);
        }
        $this->same(array(), CompatCheck::scan('<?php class R { public function match($a) { return $a === 1; } }'), 'method named match is allowed');
    }

    public function testMigrateAgainKeepsData()
    {
        $before = (int) DB::value('SELECT COUNT(*) FROM roles') + (int) DB::value('SELECT COUNT(*) FROM permissions') + (int) DB::value('SELECT COUNT(*) FROM departments');
        DB::useConnection('migrate');
        $ran = (new Migrator())->migrate();
        DB::useConnection('app');
        (new \Omps\Database\Seeder())->run();
        $after = (int) DB::value('SELECT COUNT(*) FROM roles') + (int) DB::value('SELECT COUNT(*) FROM permissions') + (int) DB::value('SELECT COUNT(*) FROM departments');
        $this->same(array(), $ran, 'nothing pending');
        $this->same($before, $after, 'row counts unchanged');
    }

    public function testStrictModeRejectsTruncation()
    {
        $mode = (string) DB::value('SELECT @@SESSION.sql_mode');
        $this->contains('STRICT_TRANS_TABLES', $mode);
        $failed = false;
        try {
            DB::table('departments')->insert(array('path' => '/', 'code' => str_repeat('x', 60), 'name' => 'x', 'created_at' => now()));
        } catch (\PDOException $e) {
            $failed = true;
        }
        $this->ok($failed, 'over-long value must fail, not be truncated');
    }
}
