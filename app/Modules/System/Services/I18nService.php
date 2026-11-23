<?php
namespace Omps\Modules\System\Services;

use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Core\ValidationException;
use Omps\Core\Xlsx;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Files;

/**
 * 多语言管理 (design §16, phase 9; super administrator).
 *
 * Entries = every program text (language files, key "module.name") plus every configuration text
 * (translations table, key "namespace:key": field names, options, statistics titles, role and menu
 * names, login picture captions). The base language (简体中文) is the original column.
 * A translation typed here is stored in `translations`: program texts under namespace 'ui', laid
 * over the files; configuration texts under their own namespace.
 * Completeness = translated entries ÷ all entries; a language can be enabled only at 100%, so an
 * enabled language never shows text of another language.
 */
class I18nService
{
    const PER_PAGE = 100;
    const CODE = '/^[a-z]{2,3}(-[A-Z]{2}|-[A-Z][a-z]{3}(-[A-Z]{2})?)?$/';

    private static function base()
    {
        return (string) config('app.base_language', 'zh-CN');
    }

    private static function lang($code)
    {
        $l = DB::table('languages')->where('code', (string) $code)->first();
        if (!$l) {
            throw HttpException::notFound();
        }
        return $l;
    }

    /** Configuration entries that exist in the base language: ["ns:key" => text]. */
    private static function configEntries($code)
    {
        $out = array();
        foreach (DB::table('translations')->where('lang', (string) $code)->where('namespace', '<>', 'ui')->orderBy('namespace')->orderBy('tkey')->get() as $r) {
            $out[$r['namespace'] . ':' . $r['tkey']] = (string) $r['text'];
        }
        return $out;
    }

    /** Current text of every entry of a language: ["key" => text] ('' = missing). */
    private static function texts($code)
    {
        $out = array();
        $file = Lang::filePack($code);
        $over = Lang::overrides($code);
        foreach (Lang::filePack(self::base()) as $k => $v) {
            $out[$k] = isset($over[$k]) ? $over[$k] : (isset($file[$k]) ? $file[$k] : '');
        }
        $cfg = self::configEntries($code);
        foreach (self::configEntries(self::base()) as $k => $v) {
            $out[$k] = isset($cfg[$k]) ? $cfg[$k] : '';
        }
        return $out;
    }

    public static function completeness($code)
    {
        $texts = self::texts($code);
        $done = 0;
        foreach ($texts as $v) {
            $done += trim($v) !== '' ? 1 : 0;
        }
        $total = count($texts);
        return array('done' => $done, 'total' => $total, 'pct' => $total ? (int) floor($done * 100 / $total) : 100, 'missing' => $total - $done);
    }

    public static function languages()
    {
        $out = array();
        foreach (DB::table('languages')->orderBy('sort')->orderBy('id')->get() as $l) {
            $c = self::completeness((string) $l['code']);
            $out[] = array('code' => (string) $l['code'], 'name' => (string) $l['name'], 'enabled' => (int) $l['enabled'] === 1, 'default' => (int) $l['is_default'] === 1,
                'base' => (string) $l['code'] === self::base(), 'done' => $c['done'], 'total' => $c['total'], 'pct' => $c['pct'], 'missing' => $c['missing'],
                'files' => (bool) glob(OMPS_ROOT . '/resources/languages/' . $l['code'] . '/*.json'));
        }
        $mods = array();
        foreach (array_unique(array_values(Lang::keyFiles(self::base()))) as $m) {
            $mods[] = $m;
        }
        sort($mods);
        $ns = array();
        foreach (DB::select("SELECT DISTINCT namespace FROM translations WHERE lang = ? AND namespace <> 'ui' ORDER BY namespace", array(self::base())) as $r) {
            $ns[] = 'db:' . $r['namespace'];
        }
        $missing = array();
        try {
            foreach (DB::table('missing_translations')->orderBy('last_seen', 'DESC')->limit(100)->get() as $m) {
                $missing[] = array('lang' => (string) $m['lang'], 'key' => (string) $m['tkey'], 'hits' => (int) $m['hits'], 'last' => (string) $m['last_seen']);
            }
        } catch (\Exception $e) {
            $missing = array();
        }
        return array('languages' => $out, 'modules' => array_merge($mods, $ns), 'base' => self::base(), 'missing' => $missing);
    }

    /** Forget the recorded missing translations (after they were fixed). */
    public static function clearMissing(Request $req)
    {
        $n = (int) DB::value('SELECT COUNT(*) FROM missing_translations');
        DB::statement('DELETE FROM missing_translations WHERE id > 0');
        Audit::op($req->user, 'i18n', 'missing_cleared', 'language', null, '', array('n' => (int) $n), $req->ip());
        return array('ok' => true);
    }

    public static function add(Request $req, array $in)
    {
        $code = trim(isset($in['code']) ? (string) $in['code'] : '');
        $name = trim(isset($in['name']) ? (string) $in['name'] : '');
        if (!preg_match(self::CODE, $code)) {
            throw ValidationException::field('code', 'i18n.err.code');
        }
        if ($name === '' || mb_strlen($name) > 50) {
            throw ValidationException::field('name', 'validation.required', array('field' => __('i18n.lang_name')));
        }
        if (DB::table('languages')->where('code', $code)->exists()) {
            throw ValidationException::field('code', 'i18n.err.exists');
        }
        DB::table('languages')->insert(array('code' => $code, 'name' => $name, 'enabled' => 0, 'is_default' => 0,
            'sort' => (int) DB::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM languages'), 'created_at' => now()));
        Audit::change($req, 'i18n', 'language_added', 'language', null, $code, null, array('code' => $code, 'name' => $name));
        Lang::clearCache();
        return array('code' => $code);
    }

    /** Name, enabled (only at 100 %), default. */
    public static function update(Request $req, $code, array $in)
    {
        $l = self::lang($code);
        $set = array();
        if (isset($in['name'])) {
            $name = trim((string) $in['name']);
            if ($name === '' || mb_strlen($name) > 50) {
                throw ValidationException::field('name', 'validation.required', array('field' => __('i18n.lang_name')));
            }
            $set['name'] = $name;
        }
        if (isset($in['enabled'])) {
            $on = (bool) $in['enabled'];
            if ($on && (int) $l['enabled'] !== 1) {
                $c = self::completeness($code);
                if ($c['missing'] > 0) {
                    throw new HttpException(409, 'conflict', 'i18n.err.incomplete', array('n' => $c['missing']));
                }
            }
            if (!$on && ((string) $code === self::base() || (int) $l['is_default'] === 1)) {
                throw HttpException::conflict('i18n.err.keep_on');
            }
            $set['enabled'] = $on ? 1 : 0;
        }
        if ($set) {
            $set['updated_at'] = now();
            DB::table('languages')->where('code', (string) $code)->update($set);
            if (isset($set['enabled']) && !$set['enabled']) {
                DB::table('users')->where('lang', (string) $code)->update(array('lang' => null));
            }
        }
        Lang::clearCache();
        if (!empty($in['default'])) {
            if (!LanguageService::setDefault((string) $code)) {
                throw HttpException::conflict('settings.err.language_disabled');
            }
        }
        Audit::change($req, 'i18n', 'language_changed', 'language', (int) $l['id'], (string) $code,
            array('name' => (string) $l['name'], 'enabled' => (int) $l['enabled'], 'default' => (int) $l['is_default']),
            array_merge($set, empty($in['default']) ? array() : array('default' => 1)));
        return array('ok' => true);
    }

    public static function delete(Request $req, $code)
    {
        $l = self::lang($code);
        if ((int) $l['enabled'] === 1 || (string) $code === self::base()) {
            throw HttpException::conflict('i18n.err.delete_enabled');
        }
        DB::transaction(function () use ($code) {
            DB::table('translations')->where('lang', (string) $code)->delete();
            DB::table('languages')->where('code', (string) $code)->delete();
        });
        Audit::change($req, 'i18n', 'language_deleted', 'language', (int) $l['id'], (string) $code, array('name' => (string) $l['name']), null);
        Lang::clearCache();
        return array('ok' => true);
    }

    /** Label of an entry's module (file or configuration namespace) in the current language. */
    private static function moduleOf($key, array $files)
    {
        if (strpos($key, ':') !== false) {
            return 'db:' . substr($key, 0, strpos($key, ':'));
        }
        return isset($files[$key]) ? $files[$key] : '';
    }

    /** One page of entries for translating into $code. */
    public static function entries($code, array $f)
    {
        list($rows, $ref) = self::rows($code, $f);
        $page = max(1, isset($f['page']) ? (int) $f['page'] : 1);
        return array('total' => count($rows), 'page' => $page, 'per_page' => self::PER_PAGE, 'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'ref' => $ref, 'completeness' => self::completeness($code));
    }

    /** All entries matching the filters: [rows, reference language or null]. */
    private static function rows($code, array $f)
    {
        self::lang($code);
        $base = self::base();
        $ref = $code === 'en-US' ? $base : 'en-US';
        $src = self::texts($base);
        $refTexts = $ref !== $base && DB::table('languages')->where('code', $ref)->exists() ? self::texts($ref) : array();
        $cur = self::texts($code);
        $files = Lang::keyFiles($base);
        $over = Lang::overrides($code);
        $mod = isset($f['module']) ? (string) $f['module'] : '';
        $kw = mb_strtolower(trim(isset($f['kw']) ? (string) $f['kw'] : ''), 'UTF-8');
        $missing = !empty($f['missing']);
        $rows = array();
        foreach ($src as $k => $s) {
            $m = self::moduleOf($k, $files);
            if ($mod !== '' && $m !== $mod) {
                continue;
            }
            $c = isset($cur[$k]) ? $cur[$k] : '';
            if ($missing && trim($c) !== '') {
                continue;
            }
            $r = isset($refTexts[$k]) ? $refTexts[$k] : '';
            if ($kw !== '' && mb_strpos(mb_strtolower($k . ' ' . $s . ' ' . $r . ' ' . $c, 'UTF-8'), $kw) === false) {
                continue;
            }
            $rows[] = array('key' => $k, 'module' => $m, 'source' => $s, 'ref' => $r, 'text' => $c, 'changed' => isset($over[$k]));
        }
        return array($rows, $refTexts ? $ref : null);
    }

    /**
     * Save translations [{key, text}]. A program text equal to the file is stored as no change;
     * an enabled language may not lose a translation.
     */
    public static function save(Request $req, $code, array $items)
    {
        $l = self::lang($code);
        $base = self::base();
        $src = self::texts($base);
        $file = Lang::filePack($code);
        $errors = array();
        $plan = array();
        foreach ($items as $i => $it) {
            $k = isset($it['key']) ? (string) $it['key'] : '';
            $text = isset($it['text']) ? trim(str_replace("\r", '', (string) $it['text'])) : '';
            if (!isset($src[$k])) {
                $errors[$k] = __('i18n.err.unknown_key');
                continue;
            }
            if (mb_strlen($text) > 2000) {
                $errors[$k] = __('validation.max', array('field' => $k, 'max' => 2000));
                continue;
            }
            $isCfg = strpos($k, ':') !== false;
            $missingAfter = $text === '' && ($isCfg || !isset($file[$k]) || $file[$k] === '');
            if ($missingAfter && (int) $l['enabled'] === 1) {
                $errors[$k] = __('i18n.err.required_enabled');
                continue;
            }
            // placeholders such as {name} must stay
            preg_match_all('/\{(\w+)\}/', $src[$k], $a);
            preg_match_all('/\{(\w+)\}/', $text, $b);
            if ($text !== '' && array_diff($a[1], $b[1])) {
                $errors[$k] = __('i18n.err.params', array('params' => '{' . implode('} {', array_diff($a[1], $b[1])) . '}'));
                continue;
            }
            $plan[] = array($k, $text, $isCfg);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $uid = $req->userId();
        DB::transaction(function () use ($plan, $code, $file, $uid) {
            foreach ($plan as $p) {
                list($k, $text, $isCfg) = $p;
                if ($isCfg) {
                    list($ns, $tkey) = explode(':', $k, 2);
                } else {
                    $ns = 'ui';
                    $tkey = $k;
                }
                DB::table('translations')->where('namespace', $ns)->where('tkey', $tkey)->where('lang', (string) $code)->delete();
                if ($text !== '' && ($isCfg || !isset($file[$k]) || $file[$k] !== $text)) {
                    DB::table('translations')->insert(array('namespace' => $ns, 'tkey' => $tkey, 'lang' => (string) $code, 'text' => $text, 'updated_at' => now(), 'updated_by' => $uid));
                }
            }
        });
        Lang::clearCache();
        Audit::op($req->user, 'i18n', 'translations_saved', 'language', (int) $l['id'], (string) $code, array('n' => count($plan)), $req->ip());
        return array('saved' => count($plan), 'completeness' => self::completeness($code));
    }

    /** All entries as an Excel sheet for a translator. */
    public static function export(Request $req, $code)
    {
        $l = self::lang($code);
        $rows = array();
        foreach (self::rows($code, array())[0] as $r) {
            $rows[] = array($r['key'], $r['module'], $r['source'], $r['ref'], $r['text']);
        }
        $bytes = Xlsx::write(array(__('i18n.col.key'), __('i18n.col.module'), __('i18n.col.source'), __('i18n.col.ref'), __('i18n.col.text') . ' · ' . $l['name']), $rows, (string) $code);
        Audit::op($req->user, 'i18n', 'translations_exported', 'language', (int) $l['id'], (string) $code, null, $req->ip());
        return new Response($bytes, 200, array('Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="translations.xlsx"; filename*=UTF-8\'\'' . rawurlencode(Files::safeName(__('i18n.export_name', array('lang' => $l['name'])), 80) . '.xlsx')));
    }

    /**
     * Import a sheet (key in column 1, translation in column 5). $run = false only checks.
     * Rows with an unknown key or broken placeholders are reported and never imported; empty
     * translations are skipped.
     */
    public static function import(Request $req, $code, $run)
    {
        self::lang($code);
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        if (!$list) {
            throw ValidationException::field('file', 'file.err.none');
        }
        $up = $list[0];
        $ext = strtolower((string) pathinfo((string) $up['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, array('xlsx', 'csv'), true)) {
            throw ValidationException::field('file', 'import.err.unreadable');
        }
        try {
            $table = Xlsx::readTable($up['tmp_name'], $ext);
        } catch (\Exception $e) {
            throw ValidationException::field('file', 'import.err.unreadable');
        }
        $src = self::texts(self::base());
        $cur = self::texts($code);
        $ok = array();
        $errors = array();
        $skipped = 0;
        foreach (array_slice($table, 1) as $i => $row) {
            $k = isset($row[0]) ? trim((string) $row[0]) : '';
            $text = isset($row[4]) ? trim(str_replace("\r", '', (string) $row[4])) : '';
            if ($k === '' && $text === '') {
                continue;
            }
            if (!isset($src[$k])) {
                $errors[] = array('row' => $i + 2, 'key' => $k, 'msg' => __('i18n.err.unknown_key'));
                continue;
            }
            if ($text === '' || $text === $cur[$k]) {
                $skipped++;
                continue;
            }
            preg_match_all('/\{(\w+)\}/', $src[$k], $a);
            preg_match_all('/\{(\w+)\}/', $text, $b);
            if (array_diff($a[1], $b[1])) {
                $errors[] = array('row' => $i + 2, 'key' => $k, 'msg' => __('i18n.err.params', array('params' => '{' . implode('} {', array_diff($a[1], $b[1])) . '}')));
                continue;
            }
            $ok[] = array('key' => $k, 'text' => $text);
        }
        $out = array('ok' => count($ok), 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 200), 'error_count' => count($errors), 'imported' => false);
        if ($run && $ok) {
            self::save($req, $code, $ok);
            $out['imported'] = true;
            $out['completeness'] = self::completeness($code);
        }
        return $out;
    }
}
