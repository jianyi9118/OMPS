<?php
namespace Omps\Modules\Custom\Services;

use Omps\Core\DocxFill;
use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Documents;
use Omps\Engines\Files;

/**
 * 自定义 → 文档模板 (phase 8, super administrator).
 * Per document type: the built-in layout plus uploaded Word templates; exactly one is the default.
 * A new upload becomes the default at once (one step); "set as default" switches back. Every template
 * can be tried with sample data that shows each placeholder's name where its value goes.
 */
class DocTemplateService
{
    private static function type($type)
    {
        if (!isset(Documents::TYPES[$type])) {
            throw ValidationException::field('type', 'validation.in', array('field' => __('doc.col.type')));
        }
        return $type;
    }

    public static function index()
    {
        $types = array();
        foreach (array_keys(Documents::TYPES) as $type) {
            $rows = DB::select('SELECT t.*, f.original_name, f.size, u.display_name AS by_name FROM doc_templates t
                LEFT JOIN file_relations r ON r.id = t.relation_id LEFT JOIN files f ON f.id = r.file_id LEFT JOIN users u ON u.id = t.created_by
                WHERE t.doc_type = ? ORDER BY t.id', array($type));
            $hasDefault = false;
            $list = array();
            foreach ($rows as $r) {
                $hasDefault = $hasDefault || (int) $r['is_default'] === 1;
                $unknown = json_decode((string) $r['names_unknown'], true);
                $ok = json_decode((string) $r['names_ok'], true);
                $list[] = array('id' => (int) $r['id'], 'name' => (string) $r['name'], 'file' => str_or_empty($r['original_name']), 'size' => (int) $r['size'],
                    'by' => str_or_empty($r['by_name']), 'at' => (string) $r['created_at'], 'default' => (int) $r['is_default'] === 1,
                    'unknown' => is_array($unknown) ? $unknown : array(), 'ok' => is_array($ok) ? count($ok) : 0);
            }
            array_unshift($list, array('id' => 0, 'name' => __('doc.builtin'), 'builtin' => true, 'default' => !$hasDefault));
            $types[] = array('type' => $type, 'templates' => $list, 'help' => Documents::help($type));
        }
        return array('types' => $types, 'available' => Documents::available());
    }

    public static function upload(Request $req, $type)
    {
        $type = self::type($type);
        if (!Documents::available()) {
            throw HttpException::bad('doc.err.no_zip');
        }
        $list = isset($req->files['file']) ? $req->files['file'] : array();
        if (!$list) {
            throw ValidationException::field('file', 'file.err.none');
        }
        $up = $list[0];
        if (strtolower((string) pathinfo((string) $up['name'], PATHINFO_EXTENSION)) !== 'docx') {
            throw ValidationException::field('file', 'doc.err.not_docx');
        }
        Files::check($up);
        try {
            $names = DocxFill::scan($up['tmp_name']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::field('file', 'doc.err.not_docx');
        }
        list($ok, $unknown) = Documents::classify($type, $names);
        if (!$ok) {
            throw ValidationException::field('file', 'doc.err.no_placeholders');
        }
        $name = mb_substr(trim((string) pathinfo((string) $up['name'], PATHINFO_FILENAME)), 0, 100);
        $uid = $req->userId();
        $id = DB::transaction(function () use ($req, $up, $type, $name, $ok, $unknown, $uid) {
            $stored = Files::store($req, $up, 'doc_template', 0, $type, array(Files::folder('file.folder.system'), Files::folder('file.folder.doc_template')));
            DB::table('doc_templates')->where('doc_type', $type)->update(array('is_default' => 0));
            return DB::table('doc_templates')->insert(array('doc_type' => $type, 'name' => $name !== '' ? $name : $type, 'relation_id' => (int) $stored['relation_id'],
                'names_ok' => json_encode(array_values($ok), JSON_UNESCAPED_UNICODE), 'names_unknown' => json_encode(array_values($unknown), JSON_UNESCAPED_UNICODE),
                'is_default' => 1, 'created_by' => $uid, 'created_at' => now()));
        });
        Audit::change($req, 'custom', 'doc_template_added', 'doc_template', $id, $name, null, array('type' => $type, 'unknown' => $unknown));
        return array('id' => (int) $id, 'ok' => $ok, 'unknown' => $unknown);
    }

    private static function find($id)
    {
        $r = DB::table('doc_templates')->where('id', (int) $id)->first();
        if (!$r) {
            throw HttpException::notFound();
        }
        return $r;
    }

    /** $id 0 = the built-in layout of $type. */
    public static function setDefault(Request $req, $type, $id)
    {
        $type = self::type($type);
        $name = __('doc.builtin');
        if ((int) $id > 0) {
            $r = self::find($id);
            if ((string) $r['doc_type'] !== $type) {
                throw HttpException::notFound();
            }
            $name = (string) $r['name'];
        }
        DB::transaction(function () use ($type, $id) {
            DB::table('doc_templates')->where('doc_type', $type)->update(array('is_default' => 0));
            if ((int) $id > 0) {
                DB::table('doc_templates')->where('id', (int) $id)->update(array('is_default' => 1));
            }
        });
        Audit::change($req, 'custom', 'doc_template_default', 'doc_template', (int) $id, $name, null, array('type' => $type));
        return array('ok' => true);
    }

    public static function delete(Request $req, $id)
    {
        $r = self::find($id);
        DB::transaction(function () use ($req, $r) {
            DB::table('doc_templates')->where('id', (int) $r['id'])->delete();
            $rel = DB::table('file_relations')->where('id', (int) $r['relation_id'])->first();
            if ($rel) {
                Files::removeRelations(array($rel), $req->userId());
            }
        });
        Audit::change($req, 'custom', 'doc_template_deleted', 'doc_template', (int) $r['id'], (string) $r['name'], array('type' => (string) $r['doc_type']), null);
        return array('ok' => true);
    }

    /** The uploaded file as it is. */
    public static function file(Request $req, $id)
    {
        $r = self::find($id);
        return Files::download($req, Files::relation((int) $r['relation_id']), false);
    }

    /** The built-in layout with its placeholders: the starting point for a unit's own template. */
    public static function sample($type)
    {
        $type = self::type($type);
        if (!Documents::available()) {
            throw HttpException::bad('doc.err.no_zip');
        }
        return Documents::docxResponse(Documents::builtin($type), __('doc.sample_file', array('type' => __('doc.type.' . $type))));
    }

    /** Fill a template with sample data (each placeholder shows its own name). */
    public static function test($type, $id)
    {
        $type = self::type($type);
        if (!Documents::available()) {
            throw HttpException::bad('doc.err.no_zip');
        }
        $model = Documents::sample($type);
        if ((int) $id > 0) {
            $r = self::find($id);
            $path = Documents::templatePath($r);
            if ($path === null) {
                throw HttpException::bad('doc.err.missing_file');
            }
            $bytes = Documents::fillFile($path, $type, $model);
            $name = (string) $r['name'];
        } else {
            $bytes = Documents::fillBytes(Documents::builtin($type), $type, $model);
            $name = __('doc.builtin');
        }
        return Documents::docxResponse($bytes, __('doc.test_file', array('name' => $name)));
    }
}
