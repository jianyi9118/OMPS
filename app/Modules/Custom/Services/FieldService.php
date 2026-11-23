<?php
namespace Omps\Modules\Custom\Services;

use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\CustomFields;
use Omps\Modules\System\Services\LanguageService;

/**
 * 字段设计 (design §15): the super admin adds fields to personnel records, devices and plans.
 *  - The name is entered in every enabled language (translations cf / cfo), so switching language
 *    never shows another language.
 *  - The key and type are fixed once a field has values; options with values can only be disabled.
 *  - A field with values cannot be deleted, only disabled (values are kept).
 */
class FieldService
{
    private static function names($ns, $key)
    {
        $out = array();
        foreach (DB::table('translations')->where('namespace', $ns)->where('tkey', $key)->get() as $t) {
            $out[(string) $t['lang']] = (string) $t['text'];
        }
        return $out;
    }

    private static function setNames($ns, $key, array $names, $uid)
    {
        foreach ($names as $lang => $text) {
            DB::table('translations')->where('namespace', $ns)->where('tkey', $key)->where('lang', $lang)->delete();
            DB::table('translations')->insert(array('namespace' => $ns, 'tkey' => $key, 'lang' => $lang, 'text' => $text, 'updated_at' => now(), 'updated_by' => $uid));
        }
    }

    private static function languages()
    {
        $out = array();
        foreach (Lang::enabledCodes() as $c) {
            $out[] = array('code' => $c, 'name' => LanguageService::name($c));
        }
        return $out;
    }

    public static function index($entity)
    {
        if (!in_array($entity, CustomFields::ENTITIES, true)) {
            $entity = 'personnel';
        }
        $counts = array();
        foreach (DB::select('SELECT field_id, COUNT(*) AS n FROM custom_field_values GROUP BY field_id') as $r) {
            $counts[(int) $r['field_id']] = (int) $r['n'];
        }
        $rows = array();
        foreach (CustomFields::defs($entity, true) as $d) {
            $d['names'] = self::names('cf', CustomFields::labelKey($entity, $d['key']));
            foreach ($d['options'] as $i => $o) {
                $d['options'][$i]['names'] = self::names('cfo', $d['id'] . '.' . $o['key']);
                $d['options'][$i]['used'] = (int) DB::value('SELECT COUNT(*) FROM custom_field_values WHERE field_id = ? AND v_string = ?', array($d['id'], $o['key']));
            }
            $d['values'] = isset($counts[$d['id']]) ? $counts[$d['id']] : 0;
            $rows[] = $d;
        }
        return array('entity' => $entity, 'rows' => $rows, 'types' => CustomFields::TYPES, 'search_types' => CustomFields::SEARCH_TYPES,
            'stat_types' => CustomFields::STAT_TYPES, 'languages' => self::languages(), 'entities' => CustomFields::ENTITIES);
    }

    /** Names in every enabled language, each required and unique within the entity for that language. */
    private static function checkNames($in, $prefix, array $taken = array())
    {
        $errors = array();
        $out = array();
        foreach (Lang::enabledCodes() as $code) {
            $v = isset($in[$code]) ? trim((string) $in[$code]) : '';
            if ($v === '' || mb_strlen($v) > 40) {
                $errors[$prefix . $code] = __('cf.err.name', array('lang' => LanguageService::name($code)));
                continue;
            }
            if (isset($taken[$code]) && in_array(mb_strtolower($v), $taken[$code], true)) {
                $errors[$prefix . $code] = __('cf.err.name_taken', array('name' => $v));
                continue;
            }
            $out[$code] = $v;
        }
        return array($out, $errors);
    }

    public static function save(Request $req, $id, array $in)
    {
        $field = $id ? DB::table('custom_fields')->find((int) $id) : null;
        if ($id && !$field) {
            throw HttpException::notFound();
        }
        $entity = $field ? (string) $field['entity'] : (isset($in['entity']) ? (string) $in['entity'] : '');
        if (!in_array($entity, CustomFields::ENTITIES, true)) {
            throw ValidationException::field('entity', 'cf.err.entity');
        }
        $errors = array();
        $hasValues = $field && DB::table('custom_field_values')->where('field_id', (int) $field['id'])->exists();
        $key = $field ? (string) $field['field_key'] : (isset($in['field_key']) ? trim((string) $in['field_key']) : '');
        if (!$field) {
            if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $key)) {
                $errors['field_key'] = __('cf.err.key');
            } elseif (DB::table('custom_fields')->where('entity', $entity)->where('field_key', $key)->exists()) {
                $errors['field_key'] = __('cf.err.key_taken');
            }
        }
        $type = isset($in['data_type']) ? (string) $in['data_type'] : ($field ? (string) $field['data_type'] : '');
        if (!in_array($type, CustomFields::TYPES, true)) {
            $errors['data_type'] = __('validation.in', array('field' => __('cf.field.type')));
        } elseif ($hasValues && $type !== (string) $field['data_type']) {
            $errors['data_type'] = __('cf.err.type_locked');
        }
        // names unique per entity and language
        $taken = array();
        foreach (DB::table('custom_fields')->where('entity', $entity)->get() as $f) {
            if ($field && (int) $f['id'] === (int) $field['id']) {
                continue;
            }
            foreach (self::names('cf', CustomFields::labelKey($entity, $f['field_key'])) as $lang => $text) {
                $taken[$lang][] = mb_strtolower($text);
            }
        }
        list($names, $nameErr) = self::checkNames(isset($in['names']) && is_array($in['names']) ? $in['names'] : array(), 'name_', $taken);
        $errors = array_merge($errors, $nameErr);
        $options = array();
        if ($type === 'select') {
            $list = isset($in['options']) && is_array($in['options']) ? array_values($in['options']) : array();
            $existing = array();
            if ($field) {
                foreach (DB::table('custom_field_options')->where('field_id', (int) $field['id'])->get() as $o) {
                    $existing[(string) $o['opt_key']] = $o;
                }
            }
            $n = count($existing);
            $seenKeys = array();
            foreach ($list as $i => $o) {
                $okey = isset($o['key']) ? (string) $o['key'] : '';
                if ($okey === '' || !isset($existing[$okey])) {
                    do {
                        $okey = 'o' . (++$n);
                    } while (isset($existing[$okey]) || isset($seenKeys[$okey]));
                }
                $seenKeys[$okey] = true;
                list($onames, $oerr) = self::checkNames(isset($o['names']) && is_array($o['names']) ? $o['names'] : array(), 'option_');
                if ($oerr) {
                    $errors['options'] = __('cf.err.option', array('n' => $i + 1));
                }
                $options[] = array('key' => $okey, 'names' => $onames, 'enabled' => !isset($o['enabled']) || !empty($o['enabled']));
            }
            $enabledCount = count(array_filter($options, function ($o) {
                return $o['enabled'];
            }));
            if (!$enabledCount) {
                $errors['options'] = __('cf.err.no_options');
            }
            // an option that is used cannot disappear: it is kept, disabled
            foreach ($existing as $okey => $o) {
                if (!isset($seenKeys[$okey])) {
                    if (DB::table('custom_field_values')->where('field_id', (int) $field['id'])->where('v_string', $okey)->exists()) {
                        $errors['options'] = __('cf.err.option_used', array('name' => Lang::db('cfo', $field['id'] . '.' . $okey)));
                    }
                }
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $flags = array(
            'is_required' => !empty($in['required']) ? 1 : 0,
            'searchable' => !empty($in['searchable']) && in_array($type, CustomFields::SEARCH_TYPES, true) ? 1 : 0,
            'statable' => !empty($in['statable']) && in_array($type, CustomFields::STAT_TYPES, true) ? 1 : 0,
            'in_list' => !empty($in['in_list']) ? 1 : 0,
            'enabled' => !isset($in['enabled']) || !empty($in['enabled']) ? 1 : 0,
        );
        $uid = $req->userId();
        $fid = DB::transaction(function () use ($field, $entity, $key, $type, $flags, $names, $options, $uid) {
            if ($field) {
                $fid = (int) $field['id'];
                DB::table('custom_fields')->where('id', $fid)->update(array_merge($flags, array('data_type' => $type, 'updated_at' => now(), 'updated_by' => $uid)));
            } else {
                $fid = DB::table('custom_fields')->insert(array_merge($flags, array('entity' => $entity, 'field_key' => $key, 'data_type' => $type,
                    'sort' => (int) DB::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM custom_fields WHERE entity = ?', array($entity)), 'created_at' => now(), 'created_by' => $uid)));
            }
            self::setNames('cf', CustomFields::labelKey($entity, $key), $names, $uid);
            if ($type === 'select') {
                $keep = array();
                foreach ($options as $i => $o) {
                    $keep[] = $o['key'];
                    if (DB::table('custom_field_options')->where('field_id', $fid)->where('opt_key', $o['key'])->exists()) {
                        DB::table('custom_field_options')->where('field_id', $fid)->where('opt_key', $o['key'])->update(array('sort' => ($i + 1) * 10, 'enabled' => $o['enabled'] ? 1 : 0));
                    } else {
                        DB::table('custom_field_options')->insert(array('field_id' => $fid, 'opt_key' => $o['key'], 'sort' => ($i + 1) * 10, 'enabled' => $o['enabled'] ? 1 : 0));
                    }
                    self::setNames('cfo', $fid . '.' . $o['key'], $o['names'], $uid);
                }
                foreach (DB::table('custom_field_options')->where('field_id', $fid)->whereNotIn('opt_key', $keep)->pluck('opt_key') as $gone) {
                    DB::table('custom_field_options')->where('field_id', $fid)->where('opt_key', $gone)->delete();
                    DB::table('translations')->where('namespace', 'cfo')->where('tkey', $fid . '.' . $gone)->delete();
                }
            }
            return $fid;
        });
        Lang::clearCache();
        CustomFields::clearCache();
        Audit::change($req, 'custom', $field ? 'custom_field_updated' : 'custom_field_created', 'custom_field', $fid, $entity . '.' . $key, null,
            array_merge(array('type' => $type, 'names' => implode(' / ', $names)), $flags));
        return array('id' => $fid);
    }

    public static function order(Request $req, array $in)
    {
        $ids = isset($in['ids']) && is_array($in['ids']) ? array_values($in['ids']) : array();
        foreach ($ids as $i => $id) {
            DB::table('custom_fields')->where('id', (int) $id)->update(array('sort' => ($i + 1) * 10));
        }
        CustomFields::clearCache();
        return array('ok' => true);
    }

    public static function delete(Request $req, $id)
    {
        $f = DB::table('custom_fields')->find((int) $id);
        if (!$f) {
            throw HttpException::notFound();
        }
        if (DB::table('custom_field_values')->where('field_id', (int) $f['id'])->exists()) {
            throw HttpException::conflict('cf.err.has_values');
        }
        // statistics widgets that used the field show "field removed" (StatService::page)
        $label = Lang::db('cf', CustomFields::labelKey($f['entity'], $f['field_key']));
        DB::transaction(function () use ($f) {
            foreach (DB::table('custom_field_options')->where('field_id', (int) $f['id'])->pluck('opt_key') as $k) {
                DB::table('translations')->where('namespace', 'cfo')->where('tkey', $f['id'] . '.' . $k)->delete();
            }
            DB::table('translations')->where('namespace', 'cf')->where('tkey', CustomFields::labelKey($f['entity'], $f['field_key']))->delete();
            DB::table('custom_fields')->where('id', (int) $f['id'])->delete();
        });
        Lang::clearCache();
        CustomFields::clearCache();
        Audit::change($req, 'custom', 'custom_field_deleted', 'custom_field', (int) $f['id'], $f['entity'] . '.' . $f['field_key'] . ' ' . $label, null, null);
        return array('ok' => true);
    }
}
