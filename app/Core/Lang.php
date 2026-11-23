<?php
namespace Omps\Core;

use Omps\Database\DB;

/**
 * Translations (design §16).
 *  - Program texts: resources/languages/<code>/*.json (flat keys such as "common.save").
 *  - Configuration texts (custom role names...): `translations` table, Lang::db().
 *  - Program texts changed in 多语言管理 (or typed for a new language) are rows of the same table
 *    with namespace 'ui'; they are laid over the files (phase 9).
 *  - Params: {name} is replaced by the value as-is (user content);
 *    a param given as array('t' => 'some.key') is itself translated (system term).
 * A missing key never falls back to another language: it shows ⟦key⟧ and is logged.
 */
class Lang
{
    /** @var string */
    private static $current = 'zh-CN';
    /** @var array code => [key => text] (files and overrides) */
    private static $packs = array();
    /** @var array code => [key => text] (files only) */
    private static $filePacks = array();
    /** @var array namespace|lang => [key => text] */
    private static $dbCache = array();
    /** @var array|null */
    private static $languages = null;
    /** @var array */
    public static $missing = array();

    public static function setLocale($code)
    {
        self::$current = (string) $code;
    }

    public static function locale()
    {
        return self::$current;
    }

    public static function pack($code = null)
    {
        $code = $code === null ? self::$current : $code;
        if (!isset(self::$packs[$code])) {
            if (!isset(self::$filePacks[$code])) {
                self::$filePacks[$code] = self::loadPack($code);
            }
            self::$packs[$code] = self::$filePacks[$code];
            foreach (self::overrides($code) as $k => $v) {
                self::$packs[$code][$k] = $v;
            }
        }
        return self::$packs[$code];
    }

    /** Program texts typed in 多语言管理 for a language: [key => text]. */
    public static function overrides($code)
    {
        $out = array();
        try {
            foreach (DB::table('translations')->where('namespace', 'ui')->where('lang', (string) $code)->get() as $r) {
                if ((string) $r['text'] !== '') {
                    $out[(string) $r['tkey']] = (string) $r['text'];
                }
            }
        } catch (\Exception $e) {
            // database not ready (installation, env:check)
        }
        return $out;
    }

    /** Language files only (i18n:check, the "original" column of 多语言管理). */
    public static function filePack($code)
    {
        if (!isset(self::$filePacks[$code])) {
            self::$filePacks[$code] = self::loadPack($code);
        }
        return self::$filePacks[$code];
    }

    /** Which file (module) each program key of a language comes from: [key => file name]. */
    public static function keyFiles($code)
    {
        $out = array();
        if (!preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', (string) $code)) {
            return $out;
        }
        foreach (glob(OMPS_ROOT . '/resources/languages/' . $code . '/*.json') ?: array() as $f) {
            $data = json_decode((string) file_get_contents($f), true);
            foreach (is_array($data) ? $data : array() as $k => $v) {
                $out[(string) $k] = basename($f, '.json');
            }
        }
        return $out;
    }

    public static function loadPack($code)
    {
        $out = array();
        if (!preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $code)) {
            return $out;
        }
        $dir = OMPS_ROOT . '/resources/languages/' . $code;
        $files = glob($dir . '/*.json');
        foreach ($files === false ? array() : $files as $f) {
            $raw = file_get_contents($f);
            $data = $raw === false ? null : json_decode($raw, true);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid language file: ' . $f);
            }
            foreach ($data as $k => $v) {
                $out[(string) $k] = (string) $v;
            }
        }
        return $out;
    }

    public static function has($key, $code = null)
    {
        $p = self::pack($code);
        return isset($p[$key]);
    }

    public static function get($key, array $params = array(), $code = null)
    {
        $p = self::pack($code);
        if (isset($p[$key])) {
            $text = $p[$key];
        } else {
            self::recordMissing($key, $code === null ? self::$current : $code);
            $text = '⟦' . $key . '⟧';
        }
        return $params ? self::fill($text, $params, $code) : $text;
    }

    public static function fill($text, array $params, $code = null)
    {
        $rep = array();
        foreach ($params as $k => $v) {
            if (is_array($v) && isset($v['t'])) {
                $v = self::get((string) $v['t'], array(), $code);
            } elseif (is_array($v)) {
                $v = '';
            }
            $rep['{' . $k . '}'] = $v === null ? '' : (string) $v;
        }
        return strtr($text, $rep);
    }

    private static function recordMissing($key, $code)
    {
        $id = $code . '|' . $key;
        if (isset(self::$missing[$id])) {
            return;
        }
        self::$missing[$id] = true;
        Logger::warning('Missing translation', array('lang' => $code, 'key' => $key));
        self::storeMissing($code, $key);
    }

    /** @var bool guards against recursion while recording */
    private static $storing = false;

    /** Record a missing translation; the first time a key is missing, the super admins are notified. */
    private static function storeMissing($code, $key)
    {
        if (self::$storing) {
            return;
        }
        self::$storing = true;
        try {
            $key = mb_substr((string) $key, 0, 190);
            $row = DB::table('missing_translations')->where('lang', (string) $code)->where('tkey', $key)->first();
            if ($row) {
                DB::statement('UPDATE missing_translations SET hits = hits + 1, last_seen = ? WHERE id = ?', array(now(), (int) $row['id']));
            } else {
                DB::table('missing_translations')->insert(array('lang' => (string) $code, 'tkey' => $key, 'hits' => 1, 'first_seen' => now(), 'last_seen' => now()));
                \Omps\Engines\Notify::send(\Omps\Engines\Notify::supers(), 'missing_translation', array('key' => $key, 'lang' => (string) $code), '/system/i18n',
                    'i18n', null, null, 'warn', 'missing:' . $code . ':' . $key);
            }
        } catch (\Throwable $e) {
            // database not ready, or recording failed: the log line above is enough
            Logger::warning('Missing translation not recorded', array('error' => $e->getMessage()));
        } finally {
            self::$storing = false;
        }
    }

    /** Configuration text from the translations table. */
    public static function db($namespace, $key, $code = null)
    {
        $code = $code === null ? self::$current : $code;
        $ck = $namespace . '|' . $code;
        if (!isset(self::$dbCache[$ck])) {
            self::$dbCache[$ck] = array();
            foreach (DB::table('translations')->where('namespace', $namespace)->where('lang', $code)->get() as $r) {
                self::$dbCache[$ck][(string) $r['tkey']] = (string) $r['text'];
            }
        }
        if (isset(self::$dbCache[$ck][$key])) {
            return self::$dbCache[$ck][$key];
        }
        self::recordMissing($namespace . ':' . $key, $code);
        return '⟦' . $namespace . ':' . $key . '⟧';
    }

    /** Configuration text, or null when there is none (no missing-translation log). */
    public static function dbText($namespace, $key, $code = null)
    {
        $code = $code === null ? self::$current : $code;
        $ck = $namespace . '|' . $code;
        if (!isset(self::$dbCache[$ck])) {
            self::$dbCache[$ck] = array();
            foreach (DB::table('translations')->where('namespace', $namespace)->where('lang', $code)->get() as $r) {
                self::$dbCache[$ck][(string) $r['tkey']] = (string) $r['text'];
            }
        }
        return isset(self::$dbCache[$ck][$key]) ? self::$dbCache[$ck][$key] : null;
    }

    public static function clearCache()
    {
        self::$dbCache = array();
        self::$packs = array();
        self::$languages = null;
    }

    /** Language rows (code, name, enabled, is_default), sorted. Falls back to the base language when the DB is not ready. */
    public static function languages()
    {
        if (self::$languages === null) {
            try {
                self::$languages = DB::table('languages')->orderBy('sort')->orderBy('id')->get();
            } catch (\Exception $e) {
                self::$languages = array(array('code' => config('app.base_language', 'zh-CN'), 'name' => (string) config('app.base_language', 'zh-CN'), 'enabled' => 1, 'is_default' => 1));
            }
        }
        return self::$languages;
    }

    public static function enabledCodes()
    {
        $out = array();
        foreach (self::languages() as $l) {
            if ((int) $l['enabled'] === 1) {
                $out[] = (string) $l['code'];
            }
        }
        return $out;
    }

    public static function defaultCode()
    {
        foreach (self::languages() as $l) {
            if ((int) $l['is_default'] === 1 && (int) $l['enabled'] === 1) {
                return (string) $l['code'];
            }
        }
        return (string) config('app.base_language', 'zh-CN');
    }

    /** Language for a request: the user's own choice, else the login-page cookie, else the system default. */
    public static function resolve($userLang, $cookieLang)
    {
        $enabled = self::enabledCodes();
        if ($userLang !== null && $userLang !== '' && in_array($userLang, $enabled, true)) {
            return $userLang;
        }
        if ($cookieLang !== '' && in_array($cookieLang, $enabled, true)) {
            return $cookieLang;
        }
        return self::defaultCode();
    }
}
