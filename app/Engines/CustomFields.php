<?php
namespace Omps\Engines;

use Omps\Core\Lang;
use Omps\Core\ValidationException;
use Omps\Database\DB;

/**
 * Custom fields (design §7.6, §15): extra fields on personnel records, devices and plans, defined
 * by the super admin. They appear in the add / edit forms, the details, list columns (in_list),
 * keyword search (searchable), statistics (statable) and exports.
 *
 *  - Labels and option names are translations (namespaces cf / cfo), one per enabled language.
 *  - Values are stored typed (string, text, integer, decimal, date) so they compare, sort and sum.
 *  - Disabled fields keep their values but are no longer shown.
 */
class CustomFields
{
    const ENTITIES = array('personnel', 'device', 'plan');
    const TYPES = array('text', 'textarea', 'int', 'decimal', 'date', 'select', 'bool');
    /** Types that can be searched by keyword / counted in statistics. */
    const SEARCH_TYPES = array('text', 'textarea', 'select');
    const STAT_TYPES = array('select', 'bool', 'int', 'decimal', 'date');

    /** @var array entity => defs */
    private static $cache = array();

    public static function clearCache()
    {
        self::$cache = array();
    }

    public static function column($type)
    {
        switch ($type) {
            case 'textarea':
                return 'v_text';
            case 'int':
            case 'bool':
                return 'v_int';
            case 'decimal':
                return 'v_decimal';
            case 'date':
                return 'v_date';
        }
        return 'v_string';
    }

    public static function labelKey($entity, $key)
    {
        return $entity . '.' . $key;
    }

    /** Field definitions with labels in the current language (enabled only unless $all). */
    public static function defs($entity, $all = false)
    {
        if (!in_array($entity, self::ENTITIES, true)) {
            return array();
        }
        $ck = $entity . ($all ? '#all' : '');
        if (isset(self::$cache[$ck])) {
            return self::$cache[$ck];
        }
        $out = array();
        try {
            $q = DB::table('custom_fields')->where('entity', $entity);
            if (!$all) {
                $q->where('enabled', 1);
            }
            $rows = $q->orderBy('sort')->orderBy('id')->get();
        } catch (\Exception $e) {
            return array(); // tables not migrated yet
        }
        $ids = array();
        foreach ($rows as $r) {
            $ids[] = (int) $r['id'];
        }
        $opts = array();
        if ($ids) {
            foreach (DB::table('custom_field_options')->whereIn('field_id', $ids)->orderBy('sort')->orderBy('id')->get() as $o) {
                $opts[(int) $o['field_id']][] = array('key' => (string) $o['opt_key'], 'label' => Lang::db('cfo', $o['field_id'] . '.' . $o['opt_key']),
                    'enabled' => (int) $o['enabled'] === 1);
            }
        }
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $out[] = array('id' => $id, 'entity' => $entity, 'key' => (string) $r['field_key'], 'type' => (string) $r['data_type'],
                'label' => Lang::db('cf', self::labelKey($entity, $r['field_key'])), 'required' => (int) $r['is_required'] === 1,
                'searchable' => (int) $r['searchable'] === 1, 'statable' => (int) $r['statable'] === 1, 'in_list' => (int) $r['in_list'] === 1,
                'enabled' => (int) $r['enabled'] === 1, 'options' => isset($opts[$id]) ? $opts[$id] : array());
        }
        self::$cache[$ck] = $out;
        return $out;
    }

    public static function listDefs($entity)
    {
        return array_values(array_filter(self::defs($entity), function ($d) {
            return $d['in_list'];
        }));
    }

    private static function raw(array $def, array $row)
    {
        switch ($def['type']) {
            case 'textarea':
                return $row['v_text'] === null ? null : (string) $row['v_text'];
            case 'int':
                return $row['v_int'] === null ? null : (int) $row['v_int'];
            case 'bool':
                return $row['v_int'] === null ? null : (int) $row['v_int'] === 1;
            case 'decimal':
                return $row['v_decimal'] === null ? null : rtrim(rtrim((string) $row['v_decimal'], '0'), '.');
            case 'date':
                return $row['v_date'] === null ? null : (string) $row['v_date'];
        }
        return $row['v_string'] === null ? null : (string) $row['v_string'];
    }

    /** Values of several records: [entity_id => [field_key => value]]. */
    public static function values($entity, array $ids)
    {
        $out = array();
        $defs = array();
        foreach (self::defs($entity) as $d) {
            $defs[$d['id']] = $d;
        }
        if (!$ids || !$defs) {
            return $out;
        }
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            foreach (DB::table('custom_field_values')->where('entity', $entity)->whereIn('entity_id', $chunk)->whereIn('field_id', array_keys($defs))->get() as $r) {
                $d = $defs[(int) $r['field_id']];
                $out[(int) $r['entity_id']][$d['key']] = self::raw($d, $r);
            }
        }
        return $out;
    }

    public static function valuesOf($entity, $id)
    {
        $v = self::values($entity, array((int) $id));
        return isset($v[(int) $id]) ? $v[(int) $id] : array();
    }

    /** Form block for a record: definitions and current values. */
    public static function block($entity, $id)
    {
        return array('defs' => self::defs($entity), 'values' => $id === null ? array() : self::valuesOf($entity, $id));
    }

    /**
     * Check submitted values ['key' => value]; missing keys are left unchanged when $partial.
     * Errors are reported on the form field "cf__<key>". Returns [field_id => typed value|null].
     */
    public static function validate($entity, $in, $partial = false)
    {
        $in = is_array($in) ? $in : array();
        $out = array();
        $errors = array();
        foreach (self::defs($entity) as $d) {
            $name = 'cf__' . $d['key'];
            if (!array_key_exists($d['key'], $in)) {
                if (!$partial && $d['required']) {
                    $errors[$name] = __('validation.required', array('field' => $d['label']));
                }
                continue;
            }
            $v = $in[$d['key']];
            if (is_string($v)) {
                $v = trim($v);
            }
            if ($v === '' || $v === null || ($d['type'] === 'bool' && $v === false && $d['required'])) {
                if ($d['required']) {
                    $errors[$name] = __('validation.required', array('field' => $d['label']));
                    continue;
                }
                if ($v === '' || $v === null) {
                    $out[$d['id']] = null;
                    continue;
                }
            }
            switch ($d['type']) {
                case 'text':
                    if (!is_scalar($v) || mb_strlen((string) $v) > 500) {
                        $errors[$name] = __('validation.max', array('field' => $d['label'], 'max' => 500));
                        continue 2;
                    }
                    break;
                case 'textarea':
                    if (!is_scalar($v) || mb_strlen((string) $v) > 20000) {
                        $errors[$name] = __('validation.max', array('field' => $d['label'], 'max' => 20000));
                        continue 2;
                    }
                    break;
                case 'int':
                    if (!(is_int($v) || (is_string($v) && preg_match('/^-?\d{1,15}$/', $v)))) {
                        $errors[$name] = __('validation.int', array('field' => $d['label']));
                        continue 2;
                    }
                    $v = (int) $v;
                    break;
                case 'decimal':
                    if (!(is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d{1,13}(\.\d{1,4})?$/', $v)))) {
                        $errors[$name] = __('validation.format', array('field' => $d['label']));
                        continue 2;
                    }
                    $v = (string) $v;
                    break;
                case 'date':
                    $dt = \DateTime::createFromFormat('Y-m-d', (string) $v);
                    if ($dt === false || $dt->format('Y-m-d') !== (string) $v) {
                        $errors[$name] = __('validation.date', array('field' => $d['label']));
                        continue 2;
                    }
                    break;
                case 'select':
                    $keys = array();
                    foreach ($d['options'] as $o) {
                        if ($o['enabled']) {
                            $keys[] = $o['key'];
                        }
                    }
                    if (!in_array((string) $v, $keys, true)) {
                        $errors[$name] = __('validation.in', array('field' => $d['label']));
                        continue 2;
                    }
                    break;
                case 'bool':
                    $v = ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0;
                    break;
            }
            $out[$d['id']] = $v;
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $out;
    }

    /** Store validated values (from validate()) for one record. */
    public static function save($entity, $entityId, array $values)
    {
        $types = array();
        foreach (self::defs($entity) as $d) {
            $types[$d['id']] = $d['type'];
        }
        foreach ($values as $fid => $v) {
            if (!isset($types[$fid])) {
                continue;
            }
            $where = DB::table('custom_field_values')->where('entity', $entity)->where('entity_id', (int) $entityId)->where('field_id', (int) $fid);
            if ($v === null) {
                $where->delete();
                continue;
            }
            $row = array('v_string' => null, 'v_text' => null, 'v_int' => null, 'v_decimal' => null, 'v_date' => null);
            $row[self::column($types[$fid])] = $v;
            if (DB::table('custom_field_values')->where('entity', $entity)->where('entity_id', (int) $entityId)->where('field_id', (int) $fid)->exists()) {
                $where->update($row);
            } else {
                DB::table('custom_field_values')->insert(array_merge($row, array('entity' => $entity, 'entity_id' => (int) $entityId, 'field_id' => (int) $fid)));
            }
        }
    }

    /** Old and new values as texts, for the audit log ([label => text]). */
    public static function diff($entity, array $before, array $values)
    {
        $b = array();
        $a = array();
        foreach (self::defs($entity) as $d) {
            if (!array_key_exists($d['id'], $values)) {
                continue;
            }
            $old = isset($before[$d['key']]) ? self::text($d, $before[$d['key']]) : '';
            $new = self::text($d, $values[$d['id']] === null ? null : ($d['type'] === 'bool' ? (int) $values[$d['id']] === 1 : $values[$d['id']]));
            if ($old !== $new) {
                $b['cf:' . $d['key']] = $old;
                $a['cf:' . $d['key']] = $new;
            }
        }
        return array($b, $a);
    }

    /** A value as text in the current language (exports, logs). */
    public static function text(array $def, $v)
    {
        if ($v === null || $v === '') {
            return '';
        }
        if ($def['type'] === 'select') {
            foreach ($def['options'] as $o) {
                if ($o['key'] === (string) $v) {
                    return $o['label'];
                }
            }
            return (string) $v;
        }
        if ($def['type'] === 'bool') {
            return __($v ? 'common.yes' : 'common.no');
        }
        return (string) $v;
    }

    /** Record ids whose searchable custom values match a keyword (text, or an option name in any language). */
    public static function matchIds($entity, $kw)
    {
        $kw = trim((string) $kw);
        if ($kw === '') {
            return array();
        }
        $ids = array();
        $like = '%' . addcslashes($kw, '%_\\') . '%';
        foreach (self::defs($entity) as $d) {
            if (!$d['searchable']) {
                continue;
            }
            if ($d['type'] === 'select') {
                $keys = array();
                foreach ($d['options'] as $o) {
                    foreach (Lang::enabledCodes() as $lc) {
                        if (mb_stripos(Lang::db('cfo', $d['id'] . '.' . $o['key'], $lc), $kw) !== false) {
                            $keys[$o['key']] = true;
                        }
                    }
                }
                if (!$keys) {
                    continue;
                }
                $rows = DB::table('custom_field_values')->where('field_id', $d['id'])->whereIn('v_string', array_keys($keys))->pluck('entity_id');
            } else {
                $col = self::column($d['type']);
                $rows = DB::select('SELECT entity_id FROM custom_field_values WHERE field_id = ? AND ' . $col . ' LIKE ?', array($d['id'], $like));
                $rows = array_map(function ($r) {
                    return $r['entity_id'];
                }, $rows);
            }
            foreach ($rows as $x) {
                $ids[(int) $x] = (int) $x;
            }
        }
        return array_values($ids);
    }

    /** Searchable custom texts per record, for searches done in PHP (plan tree): [id => text]. */
    public static function searchText($entity, array $ids)
    {
        $out = array();
        $defs = array();
        foreach (self::defs($entity) as $d) {
            if ($d['searchable']) {
                $defs[$d['key']] = $d;
            }
        }
        if (!$defs) {
            return $out;
        }
        foreach (self::values($entity, $ids) as $id => $vals) {
            $parts = array();
            foreach ($vals as $k => $v) {
                if (isset($defs[$k])) {
                    $parts[] = self::text($defs[$k], $v);
                }
            }
            $out[$id] = implode(' ', $parts);
        }
        return $out;
    }

    /** A record is deleted for good: its values go too. */
    public static function forget($entity, $entityId)
    {
        DB::table('custom_field_values')->where('entity', $entity)->where('entity_id', (int) $entityId)->delete();
    }
}
