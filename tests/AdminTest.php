<?php
namespace Omps\Tests;

use Omps\Core\Config;
use Omps\Core\Lang;
use Omps\Core\Xlsx;
use Omps\Database\DB;
use Omps\Engines\Backup;

/**
 * Phase 9: file management (visibility, delete, recycle bin and restore, duplicates), languages
 * (completeness, translations, overrides, import / export), menu management, backups and restore.
 * Every test puts back what it changes.
 */
class AdminTest extends TestCase
{
    private function txt($content, $name)
    {
        $p = sys_get_temp_dir() . '/' . $name;
        file_put_contents($p, $content);
        return array('path' => $p, 'name' => $name);
    }

    private function fileIds(array $index)
    {
        return array_map(function ($f) {
            return $f['id'];
        }, $index['files']);
    }

    public function testFileManager()
    {
        $s = (string) mt_rand(10000, 99999);
        $a = $this->dept('文件甲部' . $s);
        $b = $this->dept('文件乙部' . $s);
        $lead = $this->person('文件部长' . $s, $a);
        $p1 = $this->person('文件一' . $s, $a);
        $p2 = $this->person('文件二' . $s, $a);
        $q = $this->person('文件乙' . $s, $b);
        $uLead = $this->user('dept_head', $lead);
        $u1 = $this->user('member', $p1);
        $u2 = $this->user('member', $p2);
        $uq = $this->user('member', $q);
        $same = 'same content ' . $s;

        $this->login($u1['username']);
        $this->status(200, $this->request('GET', '/files', null, array(), array('Accept' => 'text/html')), 'everyone has the page');
        $this->status(200, $this->upload('/api/v1/me/profile/files', array($this->txt($same, 'a' . $s . '.txt')), array('group' => 'other')));
        $this->status(200, $this->upload('/api/v1/me/profile/files', array($this->txt('only one ' . $s, 'b' . $s . '.txt')), array('group' => 'other')));
        $f1 = (int) DB::value("SELECT id FROM files WHERE original_name = ?", array('a' . $s . '.txt'));
        $f1b = (int) DB::value("SELECT id FROM files WHERE original_name = ?", array('b' . $s . '.txt'));
        $this->login($u2['username']);
        $this->status(200, $this->upload('/api/v1/me/profile/files', array($this->txt($same, 'c' . $s . '.txt')), array('group' => 'other')));
        $f2 = (int) DB::value("SELECT id FROM files WHERE original_name = ?", array('c' . $s . '.txt'));
        $this->ok($f2 > $f1, 'the second copy is newer');

        // who sees what
        $mine = $this->data($this->request('GET', '/api/v1/file-manager'));
        $this->ok(in_array($f2, $this->fileIds($mine), true) && !in_array($f1, $this->fileIds($mine), true), 'members: own files only');
        $this->ok($mine['used'] === null, 'members do not see the server space');
        $row = array_values(array_filter($mine['files'], function ($f) use ($f2) {
            return $f['id'] === $f2;
        }))[0];
        $this->same(2, $row['dup'], 'duplicate count');
        $this->contains('文件二' . $s, implode(' ', $row['links']));
        $this->login($uLead['username']);
        $ids = $this->fileIds($this->data($this->request('GET', '/api/v1/file-manager')));
        $this->ok(in_array($f1, $ids, true) && in_array($f2, $ids, true), 'department head: the department');
        $this->status(200, $this->request('GET', '/api/v1/file-manager/' . $f1 . '/download'));
        $this->login($uq['username']);
        $before = $this->auditCount('access_denied');
        $this->ok(!in_array($f1, $this->fileIds($this->data($this->request('GET', '/api/v1/file-manager'))), true), 'other department: not visible');
        $this->same($before, $this->auditCount('access_denied'), 'listing does not log refusals');
        $this->status(404, $this->request('GET', '/api/v1/file-manager/' . $f1 . '/download'));
        $this->status(403, $this->request('DELETE', '/api/v1/file-manager/' . $f1), 'only the uploader deletes');

        // duplicates: the newer copy points to the kept one
        $this->login($u2['username']);
        $dups = $this->data($this->request('GET', '/api/v1/file-manager/duplicates'));
        $group = array_values(array_filter($dups['groups'], function ($g) use ($f1) {
            return $g['copies'][0]['id'] === $f1;
        }));
        $this->same(1, count($group));
        $this->ok($group[0]['copies'][0]['hidden'] && !$group[0]['copies'][0]['can_merge'], "someone else's copy: hidden, kept");
        $this->ok($group[0]['copies'][1]['can_merge']);
        $this->status(200, $this->request('POST', '/api/v1/file-manager/' . $f2 . '/merge', array()));
        $this->ok(!DB::table('files')->where('id', $f2)->exists(), 'copy removed');
        $this->same(1, (int) DB::value("SELECT COUNT(*) FROM file_relations WHERE file_id = ? AND biz_type = 'personnel' AND biz_id = ?", array($f1, $p2)), 'now a reference');

        // delete, recycle bin, restore
        $this->login($u1['username']);
        $this->status(200, $this->request('DELETE', '/api/v1/file-manager/' . $f1b));
        $this->same('trash', (string) DB::value('SELECT status FROM files WHERE id = ?', array($f1b)));
        $trash = $this->data($this->request('GET', '/api/v1/file-manager/trash'));
        $t = array_values(array_filter($trash['rows'], function ($x) use ($f1b) {
            return $x['id'] === $f1b;
        }))[0];
        $this->ok($t['restorable'] && $t['days_left'] === 30);
        $this->contains('文件一' . $s, implode(' ', $t['links']));
        $this->login($u2['username']);
        $this->status(404, $this->request('POST', '/api/v1/file-manager/' . $f1b . '/restore', array()) , 'not in my recycle bin');
        $this->login($u1['username']);
        $this->status(200, $this->request('POST', '/api/v1/file-manager/' . $f1b . '/restore', array()));
        $this->same('normal', (string) DB::value('SELECT status FROM files WHERE id = ?', array($f1b)));
        $this->same(1, (int) DB::value("SELECT COUNT(*) FROM file_relations WHERE file_id = ? AND biz_id = ?", array($f1b, $p1)), 'back on the record');
        $this->same(0, (int) DB::value('SELECT COUNT(*) FROM file_trash_relations WHERE file_id = ?', array($f1b)));
        // a file whose record is gone cannot come back
        $this->status(200, $this->request('DELETE', '/api/v1/file-manager/' . $f1b));
        DB::table('personnel_profiles')->where('id', $p1)->update(array('deleted_at' => now()));
        $this->status(409, $this->request('POST', '/api/v1/file-manager/' . $f1b . '/restore', array()));
        DB::table('personnel_profiles')->where('id', $p1)->update(array('deleted_at' => null));
        $ops = function ($a) {
            return (int) DB::value('SELECT COUNT(*) FROM operation_logs WHERE action = ?', array($a));
        };
        $this->ok($ops('file_restored') >= 1 && $ops('file_merged') >= 1, 'logged');
    }

    public function testFolderFollowsPlanName()
    {
        $root = \Omps\Engines\Files::root();
        $s = (string) mt_rand(1000, 9999);
        $old = '计划与业绩/年度' . $s . '/旧名';
        @mkdir($root . '/' . $old . '/张三', 0775, true);
        file_put_contents($root . '/' . $old . '/张三/a.txt', 'x');
        $fid = DB::table('files')->insert(array('org_id' => 1, 'rel_path' => $old . '/张三/a.txt', 'original_name' => 'a.txt', 'ext' => 'txt', 'mime' => 'text/plain', 'size' => 1,
            'sha256' => hash('sha256', 'x' . $s), 'uploaded_by' => null, 'uploaded_at' => now(), 'status' => 'normal'));
        $other = DB::table('files')->insert(array('org_id' => 1, 'rel_path' => $old . '版/b.txt', 'original_name' => 'b.txt', 'ext' => 'txt', 'mime' => 'text/plain', 'size' => 1,
            'sha256' => hash('sha256', 'y' . $s), 'uploaded_by' => null, 'uploaded_at' => now(), 'status' => 'normal'));
        $new = '计划与业绩/年度' . $s . '/新名';
        $this->ok(\Omps\Engines\Files::moveFolder($old, $new));
        $this->same($new . '/张三/a.txt', (string) DB::value('SELECT rel_path FROM files WHERE id = ?', array($fid)));
        $this->same($old . '版/b.txt', (string) DB::value('SELECT rel_path FROM files WHERE id = ?', array($other)), 'only that folder');
        $this->ok(is_file($root . '/' . $new . '/张三/a.txt') && !is_dir($root . '/' . $old));
        $this->ok(!\Omps\Engines\Files::moveFolder('计划与业绩/没有' . $s, $new . '2'), 'missing folder: nothing');
        DB::table('files')->whereIn('id', array($fid, $other))->delete();
        @unlink($root . '/' . $new . '/张三/a.txt');
        @rmdir($root . '/' . $new . '/张三');
        @rmdir($root . '/' . $new);
        @rmdir($root . '/计划与业绩/年度' . $s);
    }

    public function testLanguages()
    {
        $unit = $this->user('unit_head', $this->person('语言单位' . mt_rand(100, 999), null));
        $this->login($unit['username']);
        $this->status(403, $this->request('GET', '/api/v1/languages'), 'super admin only');
        $this->login($this->user('super')['username']);
        $list = $this->data($this->request('GET', '/api/v1/languages'));
        $zh = array_values(array_filter($list['languages'], function ($l) {
            return $l['code'] === 'zh-CN';
        }))[0];
        $this->ok($zh['base'] && $zh['pct'] === 100);
        $this->status(422, $this->request('POST', '/api/v1/languages', array('code' => 'japanese', 'name' => '日本語')), 'code format');
        $this->status(200, $this->request('POST', '/api/v1/languages', array('code' => 'ja-JP', 'name' => '日本語')));
        $r = $this->request('PUT', '/api/v1/languages/ja-JP', array('enabled' => true));
        $this->status(409, $r, 'not complete');
        $e = $this->data($this->request('GET', '/api/v1/languages/ja-JP/entries', null, array('missing' => 1)));
        $this->same($zh['total'], $e['total'], 'every entry is missing');
        $this->same(0, $e['completeness']['done']);
        // placeholders must stay
        $r = $this->request('PUT', '/api/v1/languages/ja-JP/entries', array('items' => array(array('key' => 'report.period', 'text' => '期間'))));
        $this->status(422, $r, 'placeholder missing');
        $this->status(422, $this->request('PUT', '/api/v1/languages/ja-JP/entries', array('items' => array(array('key' => 'no.such', 'text' => 'x')))));
        // translate everything (copy of the original), then enable
        $items = array();
        for ($p = 1; $p <= (int) ceil($e['total'] / $e['per_page']); $p++) {
            foreach ($this->data($this->request('GET', '/api/v1/languages/ja-JP/entries', null, array('page' => $p)))['rows'] as $row) {
                $items[] = array('key' => $row['key'], 'text' => $row['source'] === '' ? '-' : $row['source']);
            }
        }
        $this->same($e['total'], count($items));
        $this->status(200, $this->request('PUT', '/api/v1/languages/ja-JP/entries', array('items' => $items)));
        $this->status(200, $this->request('PUT', '/api/v1/languages/ja-JP', array('enabled' => true)));
        Lang::clearCache();
        $this->ok(in_array('ja-JP', Lang::enabledCodes(), true));
        $this->same(Lang::get('menu.files', array(), 'zh-CN'), Lang::get('menu.files', array(), 'ja-JP'), 'program texts from the translations table');
        $this->status(422, $this->request('PUT', '/api/v1/languages/ja-JP/entries', array('items' => array(array('key' => 'menu.files', 'text' => '')))), 'enabled: no empty text');
        // export / import
        $x = $this->request('GET', '/api/v1/languages/ja-JP/export');
        $this->same('PK', substr($x->body, 0, 2));
        $sheet = sys_get_temp_dir() . '/omps-i18n.xlsx';
        file_put_contents($sheet, Xlsx::write(array('key', 'module', 'source', 'ref', 'text'), array(array('menu.files', 'admin', '', '', 'ファイル'), array('bad.key', '', '', '', 'x'),
            array('report.period', 'report', '', '', '期間 {start} ～ {end}'))));
        $chk = $this->data($this->upload('/api/v1/languages/ja-JP/import', array(array('path' => $sheet, 'name' => 'ja.xlsx')), array('run' => '0')));
        $this->same(2, $chk['ok']);
        $this->same(1, $chk['error_count']);
        $this->ok(!$chk['imported']);
        $this->data($this->upload('/api/v1/languages/ja-JP/import', array(array('path' => $sheet, 'name' => 'ja.xlsx')), array('run' => '1')));
        Lang::clearCache();
        $this->same('ファイル', Lang::get('menu.files', array(), 'ja-JP'));
        // a person may use it; disabling sends them back to the default
        DB::table('users')->where('id', (int) $unit['id'])->update(array('lang' => 'ja-JP'));
        $this->status(409, $this->request('PUT', '/api/v1/languages/zh-CN', array('enabled' => false)), 'original stays on');
        $this->status(409, $this->request('DELETE', '/api/v1/languages/ja-JP'), 'disable first');
        $this->status(200, $this->request('PUT', '/api/v1/languages/ja-JP', array('enabled' => false)));
        $this->ok(DB::value('SELECT lang FROM users WHERE id = ?', array((int) $unit['id'])) === null);
        $this->status(200, $this->request('DELETE', '/api/v1/languages/ja-JP'));
        $this->same(0, (int) DB::value("SELECT COUNT(*) FROM translations WHERE lang = 'ja-JP'"));
        // changing an existing language's text, and back
        $this->status(200, $this->request('PUT', '/api/v1/languages/en-US/entries', array('items' => array(array('key' => 'menu.files', 'text' => 'Documents')))));
        Lang::clearCache();
        $this->same('Documents', Lang::get('menu.files', array(), 'en-US'));
        $this->status(200, $this->request('PUT', '/api/v1/languages/en-US/entries', array('items' => array(array('key' => 'menu.files', 'text' => Lang::filePack('en-US')['menu.files'])))));
        $this->same(0, (int) DB::value("SELECT COUNT(*) FROM translations WHERE namespace = 'ui' AND lang = 'en-US'"), 'same as the file: no override kept');
        @unlink($sheet);
    }

    public function testMenus()
    {
        $super = $this->user('super');
        $this->login($super['username']);
        $tree = $this->data($this->request('GET', '/api/v1/menus'))['menu'];
        $codes = array_map(function ($g) {
            return $g['code'];
        }, $tree);
        $this->ok(in_array('files', $codes, true) && in_array('g-sys', $codes, true));
        // rename "files", hide 系统管理 → 日志查询, move 系统管理 to the top
        $sysIdx = array_search('g-sys', $codes, true);
        $sys = $tree[$sysIdx];
        array_splice($tree, $sysIdx, 1);
        array_unshift($tree, $sys);
        foreach ($tree as &$g) {
            if ($g['code'] === 'files') {
                $g['names'] = array('zh-CN' => '资料库', 'en-US' => 'Library');
            }
            foreach ($g['children'] as &$c) {
                if ($c['code'] === 'sys-logs') {
                    $c['enabled'] = false;
                }
            }
            unset($c);
        }
        unset($g);
        $partial = $tree;
        foreach ($partial as &$g) {
            if ($g['code'] === 'files') {
                $g['names'] = array('zh-CN' => '资料库');
            }
        }
        unset($g);
        $this->status(422, $this->request('PUT', '/api/v1/menus', array('menu' => $partial)), 'a name for every language');
        $locked = $tree;
        foreach ($locked[0]['children'] as &$c) {
            if ($c['code'] === 'sys-menus') {
                $c['enabled'] = false;
            }
        }
        unset($c);
        $this->status(409, $this->request('PUT', '/api/v1/menus', array('menu' => $locked)), 'menu management stays reachable');
        $this->status(200, $this->request('PUT', '/api/v1/menus', array('menu' => $tree)));
        Lang::clearCache();
        // the left menu as rendered for the super admin
        $html = $this->request('GET', '/', null, array(), array('Accept' => 'text/html'))->body;
        preg_match_all('/data-code="([^"]+)"/', $html, $mm);
        $order = array_values(array_unique($mm[1]));
        $this->ok(array_search('sys-users', $order, true) < array_search('dashboard', $order, true), 'new order: 系统管理 first');
        $this->ok(!in_array('sys-logs', $order, true), 'hidden');
        $this->contains('资料库', $html);
        $this->status(200, $this->request('GET', '/system/logs', null, array(), array('Accept' => 'text/html')), 'the page itself still opens');
        // module updates keep the choices
        (new \Omps\Database\Seeder())->run();
        $this->same(0, (int) DB::value("SELECT enabled FROM menus WHERE code = 'sys-logs'"));
        $this->status(200, $this->request('POST', '/api/v1/menus/reset', array()));
        $this->same(1, (int) DB::value("SELECT enabled FROM menus WHERE code = 'sys-logs'"));
        $this->same(0, (int) DB::value("SELECT COUNT(*) FROM menus WHERE user_sort IS NOT NULL"));
        $this->same(0, (int) DB::value("SELECT COUNT(*) FROM translations WHERE namespace = 'menu'"));
    }

    public function testBackupAndRestore()
    {
        $dir = OMPS_ROOT . '/storage/temp/test-backups';
        Config::set('app.backup_path', $dir);
        $unit = $this->user('unit_head', $this->person('备份单位' . mt_rand(100, 999), null));
        $this->login($unit['username']);
        $this->status(403, $this->request('GET', '/api/v1/backups'), 'super admin only');
        $super = $this->user('super');
        $this->login($super['username']);
        // a file to back up
        $this->status(200, $this->upload('/api/v1/theme/logo', array(array('path' => $this->pngFile(), 'name' => 'logo.png'))));
        $logoFile = (string) DB::value("SELECT f.rel_path FROM files f JOIN file_relations r ON r.file_id = f.id WHERE r.biz_type = 'system' AND r.biz_group = 'logo' ORDER BY f.id DESC LIMIT 1");
        $b = $this->data($this->request('POST', '/api/v1/backups', array()));
        $this->same('ok', $b['status']);
        $this->ok(is_file($dir . '/' . $b['name']));
        $zip = new \ZipArchive();
        $zip->open($dir . '/' . $b['name']);
        $this->ok($zip->locateName('database.sql') !== false, 'database in the zip');
        $this->ok(is_file(Backup::filesDir($b['name']) . '/' . $logoFile), 'files in the folder beside it');
        $m = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();
        $this->same((string) config('app.version'), $m['version']);
        $this->ok($this->data($this->request('GET', '/api/v1/backups/' . $b['name'] . '/inspect'))['key_ok']);
        // change things after the backup
        $mark = '备份后新增' . mt_rand(1000, 9999);
        $this->dept($mark);
        $this->status(200, $this->request('DELETE', '/api/v1/theme/logo'));
        @unlink(\Omps\Engines\Files::root() . '/' . $logoFile);
        // restore needs the password
        $this->status(422, $this->request('POST', '/api/v1/backups/' . $b['name'] . '/restore', array('password' => 'wrong')));
        $r = $this->request('POST', '/api/v1/backups/' . $b['name'] . '/restore', array('password' => 'Passw0rd!x'));
        $this->status(200, $r);
        $this->ok(!DB::table('departments')->where('name', $mark)->exists(), 'data as it was');
        $this->ok(is_file(\Omps\Engines\Files::root() . '/' . $logoFile), 'files as they were');
        $names = DB::table('backup_logs')->pluck('file_name');
        $this->ok(in_array($b['name'], $names, true) && in_array($this->data($r)['before'], $names, true), 'the backup history is kept');
        $before = $this->data($r)['before'];
        $this->ok(is_file($dir . '/' . $before), 'safety backup written');
        // the next backup links unchanged files instead of copying them (no new bytes)
        sleep(1);
        $b2 = $this->data($this->request('POST', '/api/v1/backups', array()));
        $this->ok(is_file(Backup::filesDir($b2['name']) . '/' . $logoFile), 'file in the new backup');
        $this->same(Backup::size($dir . '/' . $b2['name']), (float) $b2['size'], 'unchanged files take no extra space');
        $this->ok($this->auditCount('backup_restored') >= 1);
        // automatic: once a day after the set time; old automatic ones are pruned
        $this->status(200, $this->request('PUT', '/api/v1/backups/settings', array('auto' => true, 'time' => '02:00', 'keep_days' => 7)));
        $this->status(422, $this->request('PUT', '/api/v1/backups/settings', array('auto' => true, 'time' => '25:00', 'keep_days' => 7)));
        $at = strtotime(date('Y-m-d') . ' 01:00');
        $this->ok(Backup::autoIfDue($at) === null, 'before the time');
        $at = strtotime(date('Y-m-d') . ' 02:30');
        DB::statement("DELETE FROM backup_logs WHERE kind = 'auto'");
        $auto = Backup::autoIfDue($at);
        $this->same('ok', (string) $auto['status']);
        $this->ok(Backup::autoIfDue($at) === null, 'once a day');
        DB::table('backup_logs')->where('file_name', $auto['file_name'])->update(array('started_at' => date('Y-m-d H:i:s', time() - 10 * 86400)));
        $this->same(1, Backup::prune() >= 1 ? 1 : 0);
        $this->ok(!is_file($dir . '/' . $auto['file_name']), 'pruned');
        $this->ok(is_file($dir . '/' . $b['name']), 'manual backups stay');
        // list, download, delete
        $list = $this->data($this->request('GET', '/api/v1/backups'));
        $this->ok(count($list['rows']) >= 2);
        $this->status(200, $this->request('GET', '/api/v1/backups/' . $b['name'] . '/download'));
        $this->status(404, $this->request('GET', '/api/v1/backups/..%2F.env/download'));
        $this->status(200, $this->request('DELETE', '/api/v1/backups/' . $b['name']));
        $this->ok(!is_file($dir . '/' . $b['name']) && !is_dir(Backup::filesDir($b['name'])), 'zip and folder removed');
        $this->status(200, $this->request('DELETE', '/api/v1/theme/logo'));
        foreach (glob($dir . '/*.zip') ?: array() as $f) {
            Backup::remove(basename($f));
        }
        Config::set('app.backup_path', '');
    }

    private function pngFile()
    {
        $p = sys_get_temp_dir() . '/omps-logo.png';
        file_put_contents($p, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4//8/AAX+Av4N70a4AAAAAElFTkSuQmCC'));
        return $p;
    }
}
