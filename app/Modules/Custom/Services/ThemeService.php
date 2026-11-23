<?php
namespace Omps\Modules\Custom\Services;

use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Core\Settings;
use Omps\Core\ValidationException;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Files;

/**
 * 系统主题 and Banner 管理 (design §15).
 *  - Theme: main colour (a fixed set of tested pairs for light and dark), menu style, top bar style,
 *    default light / dark / follow the computer; each person can still choose in the top bar.
 *  - Logo and login-page pictures are files (系统/主题与 Banner/), shown before sign-in through
 *    /brand/... which only serves the configured pictures.
 *  - Banner captions are entered in every enabled language (translations namespace banner).
 */
class ThemeService
{
    /** Main colours: [light, dark]; dark steps are lighter so text and buttons keep their contrast. */
    const PRESETS = array(
        'blue' => array('#1F64B8', '#4F8FDB'), 'indigo' => array('#3D4FB8', '#7C8CE6'), 'teal' => array('#0E7480', '#3CB4BF'),
        'purple' => array('#6B3FA0', '#A37BD9'), 'crimson' => array('#A8323A', '#E06A72'), 'slate' => array('#44546A', '#8FA3BF'),
    );
    const MODES = array('auto', 'light', 'dark');

    public static function current()
    {
        $p = (string) Settings::get('theme_primary', 'blue');
        return array('primary' => isset(self::PRESETS[$p]) ? $p : 'blue', 'nav' => Settings::get('theme_nav', 'dark') === 'light' ? 'light' : 'dark',
            'top' => Settings::get('theme_top', 'white') === 'primary' ? 'primary' : 'white',
            'default' => in_array(Settings::get('theme_default', 'auto'), self::MODES, true) ? (string) Settings::get('theme_default', 'auto') : 'auto');
    }

    /** What the page shell needs: data-theme, root classes, colour override, logo. */
    public static function shell($userId = null)
    {
        $c = self::current();
        $mode = $c['default'];
        if ($userId !== null) {
            $own = DB::value("SELECT pvalue FROM user_prefs WHERE user_id = ? AND pkey = 'theme'", array((int) $userId));
            if (in_array($own, self::MODES, true)) {
                $mode = (string) $own;
            }
        }
        $pair = self::PRESETS[$c['primary']];
        $css = $c['primary'] === 'blue' ? '' : ':root{--primary:' . $pair[0] . '}' .
            '@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--primary:' . $pair[1] . '}}:root[data-theme="dark"]{--primary:' . $pair[1] . '}';
        $classes = array();
        if ($c['nav'] === 'light') {
            $classes[] = 'nav-light';
        }
        if ($c['top'] === 'primary') {
            $classes[] = 'top-primary';
        }
        return array('mode' => $mode, 'theme_attr' => $mode === 'auto' ? '' : $mode, 'classes' => implode(' ', $classes), 'css' => $css,
            'logo' => self::logoRel() ? url('/brand/logo') . '?v=' . self::logoRel() : '');
    }

    private static function logoRel()
    {
        return (int) Settings::get('theme_logo', 0);
    }

    /** Enabled banners: [['rel' => id, 'caption' => text in the current language]]. */
    public static function banners($enabledOnly = true)
    {
        $out = array();
        $list = Settings::get('login_banners', array());
        foreach (is_array($list) ? $list : array() as $b) {
            if (!isset($b['rel']) || ($enabledOnly && empty($b['enabled']))) {
                continue;
            }
            $cap = DB::value("SELECT text FROM translations WHERE namespace = 'banner' AND tkey = ? AND lang = ?", array((string) (int) $b['rel'], Lang::locale()));
            $out[] = array('rel' => (int) $b['rel'], 'enabled' => !empty($b['enabled']), 'caption' => $cap === null ? '' : (string) $cap,
                'url' => url('/brand/banner/' . (int) $b['rel']));
        }
        return $out;
    }

    public static function show()
    {
        $c = self::current();
        $banners = array();
        foreach (self::banners(false) as $b) {
            $names = array();
            foreach (DB::table('translations')->where('namespace', 'banner')->where('tkey', (string) $b['rel'])->get() as $t) {
                $names[(string) $t['lang']] = (string) $t['text'];
            }
            $rel = Files::relation($b['rel']);
            $b['name'] = (string) $rel['original_name'];
            $b['captions'] = $names;
            $banners[] = $b;
        }
        $langs = array();
        foreach (Lang::enabledCodes() as $code) {
            $langs[] = array('code' => $code, 'name' => \Omps\Modules\System\Services\LanguageService::name($code));
        }
        return array_merge($c, array('presets' => self::PRESETS, 'logo' => self::logoRel() ? url('/brand/logo') . '?v=' . self::logoRel() : '', 'banners' => $banners,
            'languages' => $langs, 'unit_name' => (string) Settings::get('unit_name', '')));
    }

    public static function save(Request $req, array $in)
    {
        $before = self::current();
        $p = isset($in['primary']) ? (string) $in['primary'] : $before['primary'];
        $nav = isset($in['nav']) ? (string) $in['nav'] : $before['nav'];
        $top = isset($in['top']) ? (string) $in['top'] : $before['top'];
        $def = isset($in['default']) ? (string) $in['default'] : $before['default'];
        if (!isset(self::PRESETS[$p]) || !in_array($nav, array('dark', 'light'), true) || !in_array($top, array('white', 'primary'), true) || !in_array($def, self::MODES, true)) {
            throw ValidationException::field('primary', 'theme.err.value');
        }
        $after = array('primary' => $p, 'nav' => $nav, 'top' => $top, 'default' => $def);
        foreach ($after as $k => $v) {
            if ($before[$k] !== $v) {
                Settings::set('theme_' . $k, $v, $req->userId());
            }
        }
        list($b, $a) = Audit::diff($before, $after);
        if ($a) {
            Audit::change($req, 'custom', 'theme_changed', 'settings', null, null, $b, $a);
        }
        return self::show();
    }

    /** One's own light / dark choice (top bar). */
    public static function own(Request $req, array $in)
    {
        $mode = isset($in['mode']) ? (string) $in['mode'] : '';
        if (!in_array($mode, self::MODES, true)) {
            throw ValidationException::field('mode', 'theme.err.value');
        }
        DB::table('user_prefs')->where('user_id', $req->userId())->where('pkey', 'theme')->delete();
        DB::table('user_prefs')->insert(array('user_id' => $req->userId(), 'pkey' => 'theme', 'pvalue' => $mode));
        return array('mode' => $mode);
    }

    private static function folders()
    {
        return array(Files::folder('file.folder.system'), Files::folder('file.folder.theme'));
    }

    private static function image(Request $req, $group)
    {
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        if (!$list) {
            throw ValidationException::field('file', 'file.err.none');
        }
        $r = Files::store($req, $list[0], 'system', 0, $group, self::folders(), null, true);
        return (int) $r['relation_id'];
    }

    public static function uploadLogo(Request $req)
    {
        $rel = self::image($req, 'logo');
        $old = self::logoRel();
        Settings::set('theme_logo', $rel, $req->userId());
        if ($old) {
            self::drop($req, $old);
        }
        Audit::change($req, 'custom', 'logo_changed', 'settings', null, null, null, array('logo' => $rel));
        return self::show();
    }

    public static function removeLogo(Request $req)
    {
        $old = self::logoRel();
        Settings::set('theme_logo', 0, $req->userId());
        if ($old) {
            self::drop($req, $old);
            Audit::change($req, 'custom', 'logo_changed', 'settings', null, null, array('logo' => $old), array('logo' => null));
        }
        return self::show();
    }

    private static function drop(Request $req, $relId)
    {
        $rel = DB::table('file_relations')->where('id', (int) $relId)->first();
        if ($rel) {
            Files::removeRelations(array($rel), $req->userId());
        }
    }

    private static function bannerList()
    {
        $list = Settings::get('login_banners', array());
        return is_array($list) ? array_values($list) : array();
    }

    public static function uploadBanner(Request $req)
    {
        $list = self::bannerList();
        if (count($list) >= 5) {
            throw ValidationException::field('file', 'theme.err.banners');
        }
        $rel = self::image($req, 'banner');
        $list[] = array('rel' => $rel, 'enabled' => true);
        Settings::set('login_banners', $list, $req->userId());
        Audit::change($req, 'custom', 'banner_added', 'settings', null, null, null, array('banner' => $rel));
        return self::show();
    }

    /** Update the order, on/off and captions of all banners at once. */
    public static function saveBanners(Request $req, array $in)
    {
        $current = array();
        foreach (self::bannerList() as $b) {
            $current[(int) $b['rel']] = $b;
        }
        $list = array();
        foreach (isset($in['banners']) && is_array($in['banners']) ? $in['banners'] : array() as $b) {
            $rel = isset($b['rel']) ? (int) $b['rel'] : 0;
            if (!isset($current[$rel])) {
                continue;
            }
            $list[] = array('rel' => $rel, 'enabled' => !empty($b['enabled']));
            foreach (Lang::enabledCodes() as $code) {
                $text = isset($b['captions'][$code]) ? trim((string) $b['captions'][$code]) : '';
                if (mb_strlen($text) > 80) {
                    throw ValidationException::field('banners', 'theme.err.caption');
                }
                DB::table('translations')->where('namespace', 'banner')->where('tkey', (string) $rel)->where('lang', $code)->delete();
                if ($text !== '') {
                    DB::table('translations')->insert(array('namespace' => 'banner', 'tkey' => (string) $rel, 'lang' => $code, 'text' => $text, 'updated_at' => now(), 'updated_by' => $req->userId()));
                }
            }
            unset($current[$rel]);
        }
        foreach ($current as $rel => $b) {
            // removed from the list
            DB::table('translations')->where('namespace', 'banner')->where('tkey', (string) $rel)->delete();
            self::drop($req, $rel);
        }
        Settings::set('login_banners', $list, $req->userId());
        Audit::change($req, 'custom', 'banners_saved', 'settings', null, null, null, array('banners' => count($list)));
        return self::show();
    }

    /** Public: the logo or a configured banner, nothing else. */
    public static function serve(Request $req, $what, $relId = null)
    {
        $rel = $what === 'logo' ? self::logoRel() : (int) $relId;
        if (!$rel) {
            throw HttpException::notFound();
        }
        if ($what === 'banner') {
            $ok = false;
            foreach (self::bannerList() as $b) {
                if ((int) $b['rel'] === $rel) {
                    $ok = true;
                }
            }
            if (!$ok) {
                throw HttpException::notFound();
            }
        }
        $r = DB::selectOne("SELECT r.*, f.rel_path, f.original_name, f.mime, f.status FROM file_relations r JOIN files f ON f.id = r.file_id WHERE r.id = ? AND r.biz_type = 'system'", array($rel));
        if (!$r || (string) $r['status'] !== 'normal') {
            throw HttpException::notFound();
        }
        $path = Files::root() . '/' . $r['rel_path'];
        if (!is_file($path)) {
            throw HttpException::notFound();
        }
        $res = Response::file($path, (string) $r['original_name'], (string) $r['mime'], true);
        return $res->header('Cache-Control', 'public, max-age=86400');
    }
}
