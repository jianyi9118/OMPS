<?php
namespace Omps\Engines;

use Omps\Core\Docx;
use Omps\Core\DocxBuilder;
use Omps\Core\DocxFill;
use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Core\Settings;
use Omps\Database\DB;

/**
 * Word documents from templates (design §7.7, phase 8).
 *
 * Two document types: the comprehensive report (report) and the staff registration form (person).
 * Each has a built-in layout and may have templates uploaded by the super administrator; one of
 * them is the default. A template is an ordinary .docx with placeholders such as {{标题}} or
 * {{title}}: a placeholder may be written in any enabled language (or with its internal code).
 *
 * The business module builds a model with what the viewer may see, and this engine fills it in:
 *   ['fields' => [key => text], 'blocks' => [key => blocks], 'lists' => [key => rows],
 *    'images' => [key => image|null], 'custom' => [custom field key => text], 'fallback' => blocks]
 * 'fallback' is used when the server has no zip extension (a .doc that Word opens).
 */
class Documents
{
    const TYPES = array(
        'report' => array(
            'fields' => array('unit_name', 'title', 'period', 'conditions', 'intro', 'outro', 'as_of', 'author', 'today'),
            'blocks' => array('body'),
            'images' => array(),
            'lists' => array('results' => array('no', 'section', 'task', 'level', 'time', 'assignees', 'progress', 'person', 'result_progress', 'done', 'problems')),
        ),
        'person' => array(
            'fields' => array('unit_name', 'today', 'staff_no', 'name', 'gender', 'birth_date', 'age', 'ethnicity', 'id_type', 'id_number', 'phone', 'email',
                'address', 'hukou_address', 'dept', 'position', 'hire_date', 'status', 'remark'),
            'blocks' => array(),
            'images' => array('photo'),
            'lists' => array(
                'education' => array('no', 'period', 'start', 'end', 'school', 'country', 'major', 'degree_level', 'degree', 'graduated', 'certificate_no'),
                'work' => array('no', 'period', 'start', 'end', 'employer', 'position', 'duties', 'leave_reason'),
                'title' => array('no', 'title', 'duty', 'level', 'obtained', 'issuer'),
                'achievement' => array('no', 'name', 'type', 'date', 'issuer', 'certificate_no'),
                'family' => array('no', 'relation', 'name', 'gender', 'birth_date', 'employer', 'position', 'phone', 'address'),
                'custom' => array('no', 'label', 'value'),
            ),
        ),
    );

    private static $names = array();

    public static function clearCache()
    {
        self::$names = array();
    }

    public static function available()
    {
        return Docx::available() && class_exists('DOMDocument');
    }

    // ---------- placeholder names ----------

    private static function norm($s)
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', (string) $s), 'UTF-8');
    }

    public static function phKey($type, $key, $list = null)
    {
        if ($list !== null) {
            return $key === 'no' ? 'doc.ph.no' : 'doc.ph.' . $type . '.' . $list . '.' . $key;
        }
        return 'doc.ph.' . $type . '.' . $key;
    }

    /** Placeholder name in a language. */
    public static function phName($type, $key, $list = null, $code = null)
    {
        if ($list !== null) {
            return Lang::get(self::phKey($type, $list), array(), $code) . '.' . Lang::get(self::phKey($type, $key, $list), array(), $code);
        }
        return Lang::get(self::phKey($type, $key), array(), $code);
    }

    /** Normalised name => descriptor, over all enabled languages and the internal codes. */
    private static function nameMap($type)
    {
        if (isset(self::$names[$type])) {
            return self::$names[$type];
        }
        $def = self::TYPES[$type];
        $codes = array_unique(array_merge(Lang::enabledCodes(), array((string) config('app.base_language', 'zh-CN'))));
        $map = array();
        $add = function ($name, array $d) use (&$map) {
            $n = self::norm($name);
            if ($n !== '' && !isset($map[$n])) {
                $map[$n] = $d;
            }
        };
        foreach (array('fields' => 'text', 'blocks' => 'block', 'images' => 'image') as $group => $t) {
            foreach ($def[$group] as $k) {
                $add($k, array('t' => $t, 'k' => $k));
                foreach ($codes as $c) {
                    $add(self::phName($type, $k, null, $c), array('t' => $t, 'k' => $k));
                }
            }
        }
        foreach ($def['lists'] as $list => $cols) {
            foreach ($cols as $col) {
                $add($list . '.' . $col, array('t' => 'list', 'l' => $list, 'c' => $col));
                foreach ($codes as $c) {
                    $add(self::phName($type, $col, $list, $c), array('t' => 'list', 'l' => $list, 'c' => $col));
                }
            }
        }
        $entity = self::customEntity($type);
        if ($entity !== null) {
            foreach (CustomFields::defs($entity) as $f) {
                foreach ($codes as $c) {
                    $add(Lang::db('cf', CustomFields::labelKey($entity, $f['key']), $c), array('t' => 'cf', 'k' => $f['key']));
                }
            }
        }
        self::$names[$type] = $map;
        return $map;
    }

    private static function customEntity($type)
    {
        return $type === 'person' ? 'personnel' : null;
    }

    public static function describe($type, $name)
    {
        $map = self::nameMap($type);
        $n = self::norm(str_replace("\u{FF0E}", '.', (string) $name));
        return isset($map[$n]) ? $map[$n] : null;
    }

    /** Placeholder help in the current language: groups of names. */
    public static function help($type)
    {
        $def = self::TYPES[$type];
        $out = array('fields' => array(), 'blocks' => array(), 'images' => array(), 'lists' => array(), 'custom' => array());
        foreach (array('fields', 'blocks', 'images') as $g) {
            foreach ($def[$g] as $k) {
                $out[$g][] = self::phName($type, $k);
            }
        }
        foreach ($def['lists'] as $list => $cols) {
            $names = array();
            foreach ($cols as $c) {
                $names[] = self::phName($type, $c, $list);
            }
            $out['lists'][] = array('name' => Lang::get(self::phKey($type, $list)), 'cols' => $names);
        }
        $entity = self::customEntity($type);
        if ($entity !== null) {
            foreach (CustomFields::defs($entity) as $f) {
                $out['custom'][] = $f['label'];
            }
        }
        return $out;
    }

    /** Split template placeholders into recognised and unknown names. */
    public static function classify($type, array $names)
    {
        $ok = array();
        $unknown = array();
        foreach ($names as $n) {
            if (self::describe($type, $n) === null) {
                $unknown[] = $n;
            } else {
                $ok[] = $n;
            }
        }
        return array($ok, $unknown);
    }

    // ---------- templates ----------

    public static function templates($type)
    {
        return DB::table('doc_templates')->where('doc_type', $type)->orderBy('id')->get();
    }

    /** Choices for a download menu: the built-in layout and the uploaded templates, default first. */
    public static function choices($type)
    {
        if (!isset(self::TYPES[$type])) {
            throw HttpException::notFound();
        }
        $rows = self::templates($type);
        $hasDefault = false;
        foreach ($rows as $r) {
            $hasDefault = $hasDefault || (int) $r['is_default'] === 1;
        }
        $out = array(array('id' => 0, 'name' => __('doc.builtin'), 'default' => !$hasDefault));
        foreach ($rows as $r) {
            $out[] = array('id' => (int) $r['id'], 'name' => (string) $r['name'], 'default' => (int) $r['is_default'] === 1);
        }
        usort($out, function ($a, $b) {
            return $a['default'] === $b['default'] ? $a['id'] - $b['id'] : ($a['default'] ? -1 : 1);
        });
        return $out;
    }

    /** Template row to use (null = built-in). $tplId: null = the default, 0 = built-in, n = that template. */
    public static function pick($type, $tplId)
    {
        if ($tplId !== null && (int) $tplId > 0) {
            $r = DB::table('doc_templates')->where('id', (int) $tplId)->where('doc_type', $type)->first();
            if (!$r) {
                throw HttpException::notFound();
            }
            return $r;
        }
        if ($tplId !== null) {
            return null;
        }
        $r = DB::table('doc_templates')->where('doc_type', $type)->where('is_default', 1)->first();
        return $r ? $r : null;
    }

    public static function templatePath(array $tpl)
    {
        $rel = DB::selectOne("SELECT f.rel_path FROM file_relations r JOIN files f ON f.id = r.file_id WHERE r.id = ? AND f.status = 'normal'", array((int) $tpl['relation_id']));
        $path = $rel ? Files::root() . '/' . $rel['rel_path'] : '';
        return $path !== '' && is_file($path) ? $path : null;
    }

    // ---------- filling ----------

    private static function binder($type, array $model)
    {
        return function ($name) use ($type, $model) {
            $d = self::describe($type, $name);
            if ($d === null) {
                return null;
            }
            switch ($d['t']) {
                case 'text':
                    return array('t' => 'text', 'v' => isset($model['fields'][$d['k']]) ? (string) $model['fields'][$d['k']] : '');
                case 'block':
                    return array('t' => 'block', 'v' => isset($model['blocks'][$d['k']]) ? $model['blocks'][$d['k']] : array());
                case 'image':
                    return array('t' => 'image', 'v' => isset($model['images'][$d['k']]) ? $model['images'][$d['k']] : null);
                case 'cf':
                    return array('t' => 'text', 'v' => isset($model['custom'][$d['k']]) ? (string) $model['custom'][$d['k']] : '');
            }
            return $d;
        };
    }

    /** Fill a template file with a model; returns .docx bytes. */
    public static function fillFile($path, $type, array $model)
    {
        try {
            return DocxFill::fill($path, self::binder($type, $model), isset($model['lists']) ? $model['lists'] : array());
        } catch (\InvalidArgumentException $e) {
            throw HttpException::bad('doc.err.broken');
        }
    }

    /** Built-in layout bytes (with placeholders) for a model; null model = every section (sample). */
    public static function builtin($type, $model = null)
    {
        return $type === 'report' ? DocLayouts::report() : DocLayouts::person($model);
    }

    private static function withTemp($bytes, $fn)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'omx');
        file_put_contents($tmp, $bytes);
        try {
            return $fn($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public static function fillBytes($bytes, $type, array $model)
    {
        return self::withTemp($bytes, function ($path) use ($type, $model) {
            return self::fillFile($path, $type, $model);
        });
    }

    public static function docxResponse($bytes, $name)
    {
        return new Response($bytes, 200, array('Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="document.docx"; filename*=UTF-8\'\'' . rawurlencode(Files::safeName($name, 80) . '.docx')));
    }

    /**
     * Generate a document for download and record it.
     * @param string $name file name without extension
     */
    public static function download(Request $req, $type, array $model, $tplId, $name, $bizType, $bizId)
    {
        if (!self::available()) {
            self::record($req, $type, null, 'doc', $bizType, $bizId, $name);
            return new Response(Docx::html(isset($model['fallback']) ? $model['fallback'] : array(), $name), 200, array('Content-Type' => 'application/msword',
                'Content-Disposition' => 'attachment; filename="document.doc"; filename*=UTF-8\'\'' . rawurlencode(Files::safeName($name, 80) . '.doc')));
        }
        $tpl = self::pick($type, $tplId);
        $path = $tpl ? self::templatePath($tpl) : null;
        if ($tpl && $path === null) {
            throw HttpException::bad('doc.err.missing_file');
        }
        $bytes = $path !== null ? self::fillFile($path, $type, $model) : self::fillBytes(self::builtin($type, $model), $type, $model);
        self::record($req, $type, $tpl, 'docx', $bizType, $bizId, $name);
        return self::docxResponse($bytes, $name);
    }

    private static function record(Request $req, $type, $tpl, $format, $bizType, $bizId, $name)
    {
        DB::table('generated_documents')->insert(array('template_id' => $tpl ? (int) $tpl['id'] : null, 'doc_type' => $type, 'biz_type' => $bizType, 'biz_id' => (int) $bizId,
            'format' => $format, 'created_by' => $req->userId(), 'created_at' => now()));
        Audit::op($req->user, 'doc', 'doc_generated', $bizType, (int) $bizId, (string) $name, array('template' => $tpl ? (string) $tpl['name'] : 'builtin', 'format' => $format), $req->ip());
    }

    // ---------- sample (template check) ----------

    /** A model that shows every placeholder's name where its value would go. */
    public static function sample($type)
    {
        $def = self::TYPES[$type];
        $mark = function ($s) {
            return __('doc.sample_mark', array('name' => $s));
        };
        $m = array('fields' => array(), 'blocks' => array(), 'lists' => array(), 'images' => array(), 'custom' => array());
        foreach ($def['fields'] as $k) {
            $m['fields'][$k] = $mark(self::phName($type, $k));
        }
        foreach ($def['blocks'] as $k) {
            $m['blocks'][$k] = array(
                array('type' => 'h1', 'text' => $mark(__('doc.sample.h1')), 'tag' => $mark(__('doc.sample.tag'))),
                array('type' => 'note', 'text' => $mark(__('doc.sample.note'))),
                array('type' => 'item', 'tag' => $mark(__('doc.sample.tag')), 'text' => $mark(__('doc.sample.item'))),
                array('type' => 'h2', 'text' => $mark(__('doc.sample.h2'))),
                array('type' => 'item', 'tag' => $mark(__('doc.sample.tag')), 'text' => $mark(__('doc.sample.item'))),
                array('type' => 'p', 'text' => $mark(__('doc.sample.p'))),
            );
        }
        foreach ($def['lists'] as $list => $cols) {
            for ($i = 1; $i <= 2; $i++) {
                $row = array();
                foreach ($cols as $c) {
                    $row[$c] = $c === 'no' ? (string) $i : $mark(Lang::get(self::phKey($type, $c, $list)));
                }
                $m['lists'][$list][] = $row;
            }
        }
        foreach ($def['images'] as $k) {
            $m['images'][$k] = array('bytes' => self::grayPng(), 'ext' => 'png', 'w' => 5, 'h' => 7);
        }
        $entity = self::customEntity($type);
        if ($entity !== null && CustomFields::defs($entity)) {
            $m['lists']['custom'] = array();
            foreach (CustomFields::defs($entity) as $i => $f) {
                $m['custom'][$f['key']] = $mark($f['label']);
                $m['lists']['custom'][] = array('no' => (string) ($i + 1), 'label' => $f['label'], 'value' => $mark($f['label']));
            }
        }
        $m['fallback'] = array(array('type' => 'title', 'text' => __('doc.type.' . $type)));
        return $m;
    }

    /** A 1 x 1 light grey PNG (no GD needed). */
    private static function grayPng()
    {
        $chunk = function ($type, $data) {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress("\x00\xD9\xD9\xD9")) . $chunk('IEND', '');
    }

    /** Common fields. */
    public static function base()
    {
        return array('unit_name' => (string) Settings::get('unit_name', ''), 'today' => date('Y-m-d'));
    }

    /** A stored picture as an image for the fill engine, or null. */
    public static function image($relPath)
    {
        $path = Files::root() . '/' . $relPath;
        if (!is_file($path)) {
            return null;
        }
        $info = @getimagesize($path);
        $types = array(IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_GIF => 'gif', IMAGETYPE_BMP => 'bmp');
        if ($info === false || !isset($types[$info[2]])) {
            return null;
        }
        return array('bytes' => (string) file_get_contents($path), 'ext' => $types[$info[2]], 'w' => (int) $info[0], 'h' => (int) $info[1]);
    }
}
