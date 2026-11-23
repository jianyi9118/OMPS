<?php
namespace Omps\Modules\Personnel\Services;

use Omps\Core\Crypto;
use Omps\Core\HttpException;
use Omps\Core\Request;
use Omps\Core\ValidationException;
use Omps\Core\Validator;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Documents;
use Omps\Engines\Files;
use Omps\Engines\Permission;
use Omps\Modules\Organization\Services\StaffService;

/**
 * Full staff record (phase 2).
 *
 * Who may do what with one record:
 *  - see it: inside the data scope (members: only their own) -> StaffService::scoped()
 *  - edit directly: staff.edit with a department or wider scope; a department head's own record
 *    goes through review like everyone else's (only whole-unit roles change their own directly)
 *  - full ID number: own record, or staff.sensitive (every reveal is audited)
 *  - family: own record, or family.view; phone and address in full only for the owner or family.sensitive
 */
class PersonnelService
{
    public static function access(Request $req, $id)
    {
        $p = StaffService::scoped($req, $id);
        $u = $req->user;
        $own = $u['person_id'] !== null && (int) $u['person_id'] === (int) $p['id'];
        $scope = Permission::scope($u);
        $wide = $scope === 'unit' || $scope === 'all';
        $caps = StaffService::caps($u);
        return array($p, array(
            'own' => $own,
            'admin_edit' => $caps['edit'] && (!$own || $wide),
            'self_edit' => $own,
            'reveal' => $own || Permission::can($u, 'staff.sensitive'),
            'family' => $own || Permission::can($u, 'family.view'),
            'family_full' => $own || Permission::can($u, 'family.sensitive'),
            'family_edit' => Permission::can($u, 'family.edit') && (!$own || $wide),
            'review_direct' => $wide,
        ));
    }

    private static function row($id)
    {
        $r = DB::table('personnel_profiles p')->leftJoin('departments d', 'd.id', '=', 'p.dept_id')->active()
            ->select(array('p.*', 'd.name as dept_name'))->where('p.id', (int) $id)->first();
        if (!$r) {
            throw HttpException::notFound();
        }
        return $r;
    }

    private static function presentProfile(array $r)
    {
        $out = array('id' => (int) $r['id'], 'staff_no' => (string) $r['staff_no'], 'dept_id' => int_or_null($r['dept_id']), 'dept' => str_or_empty($r['dept_name']),
            'status' => (string) $r['status'], 'unassigned' => (int) $r['unassigned'] === 1, 'version' => (int) $r['version'],
            'id_number_mask' => str_or_empty($r['id_number_mask']), 'photo_file_id' => int_or_null($r['photo_file_id']));
        foreach (array_keys(Fields::profile()) as $k) {
            if ($k === 'id_number') {
                continue;
            }
            $out[$k] = str_or_empty($r[$k]);
        }
        return $out;
    }

    private static function entityRows($entity, $personId, array $ctx)
    {
        $defs = Fields::entities();
        $def = $defs[$entity];
        $rows = DB::table($def['table'])->where('person_id', (int) $personId)->orderBy('sort')->orderBy('id')->get();
        $out = array();
        foreach ($rows as $r) {
            $item = array('id' => (int) $r['id']);
            foreach ($def['fields'] as $k => $d) {
                if ($entity === 'family' && $k === 'phone') {
                    $item['phone'] = $ctx['family_full'] ? str_or_empty(Crypto::decrypt($r['phone_enc'])) : str_or_empty($r['phone_mask']);
                    continue;
                }
                if ($entity === 'family' && $k === 'address') {
                    $item['address'] = $ctx['family_full'] ? str_or_empty(Crypto::decrypt($r['address_enc'])) : ($r['address_enc'] !== null ? '******' : '');
                    continue;
                }
                $item[$k] = !empty($d['bool']) ? (int) $r[$k] === 1 : str_or_empty($r[$k]);
            }
            $out[] = $item;
        }
        return $out;
    }

    public static function get(Request $req, $id)
    {
        list($p, $ctx) = self::access($req, $id);
        $r = self::row($p['id']);
        $out = array('profile' => self::presentProfile($r), 'caps' => $ctx);
        foreach (array_keys(Fields::entities()) as $e) {
            if ($e === 'family' && !$ctx['family']) {
                continue;
            }
            $out[$e] = self::entityRows($e, $r['id'], $ctx);
        }
        if ($ctx['family'] && !$ctx['own']) {
            Audit::log($req->user, 'family', 'family_viewed', 'person', $r['id'], $r['name'] . ' (' . $r['staff_no'] . ')', null, null, $req->ip());
        }
        $out['files'] = Files::forRecord('personnel', $r['id']);
        $out['pending'] = ChangeRequests::forPerson((int) $r['id']);
        $out['custom'] = \Omps\Engines\CustomFields::block('personnel', (int) $r['id']);
        return $out;
    }

    /** Full ID number (own record or staff.sensitive); always audited. */
    public static function revealId(Request $req, $id)
    {
        list($p, $ctx) = self::access($req, $id);
        if (!$ctx['reveal']) {
            Audit::denied($req, 'id_number', array('person' => (int) $id));
            throw HttpException::forbidden();
        }
        $r = self::row($p['id']);
        Audit::log($req->user, 'staff', 'id_revealed', 'person', $r['id'], $r['name'] . ' (' . $r['staff_no'] . ')', null, null, $req->ip());
        return array('id_number' => str_or_empty(Crypto::decrypt($r['id_number_enc'])));
    }

    /**
     * Staff registration form (Word, phase 8). Holds exactly what the viewer may see on screen:
     * the full ID number only for the owner or staff.sensitive, family only with family.view
     * (phone and address in full only with family.sensitive). Other people's forms are audited.
     */
    public static function document(Request $req, $id, $tplId)
    {
        list($p, $ctx) = self::access($req, $id);
        $r = self::row($p['id']);
        $dict = function ($code, $v) {
            return $v === null || (string) $v === '' ? '' : __('dict.' . $code . '.' . $v);
        };
        $span = function ($a, $b) {
            return $a === '' && $b === '' ? '' : __('doc.span', array('start' => $a, 'end' => $b === '' ? __('doc.now') : $b));
        };
        $f = Documents::base();
        $prof = self::presentProfile($r);
        foreach (array('staff_no', 'name', 'birth_date', 'ethnicity', 'phone', 'email', 'address', 'hukou_address', 'position', 'hire_date', 'remark') as $k) {
            $f[$k] = (string) $prof[$k];
        }
        $f['gender'] = $dict('gender', $prof['gender']);
        $f['id_type'] = $dict('id_type', $prof['id_type']);
        $full = $ctx['reveal'] && $r['id_number_enc'] !== null;
        $f['id_number'] = $full ? str_or_empty(Crypto::decrypt($r['id_number_enc'])) : (string) $prof['id_number_mask'];
        $f['dept'] = $prof['unassigned'] ? __('staff.unassigned') : (string) $prof['dept'];
        $f['status'] = __('staff.status.' . $prof['status']);
        $f['age'] = '';
        if ($prof['birth_date'] !== '') {
            $f['age'] = (string) (int) ((new \DateTime($prof['birth_date']))->diff(new \DateTime('today'))->y);
        }
        $lists = array();
        foreach (self::entityRows('education', $r['id'], $ctx) as $e) {
            $lists['education'][] = array('period' => $span($e['start_ym'], $e['end_ym']), 'start' => $e['start_ym'], 'end' => $e['end_ym'], 'school' => $e['school'],
                'country' => $e['country_region'], 'major' => $e['major'], 'degree_level' => $dict('degree_level', $e['degree_level']), 'degree' => $dict('degree', $e['degree']),
                'graduated' => __($e['graduated'] ? 'common.yes' : 'common.no'), 'certificate_no' => $e['certificate_no']);
        }
        foreach (self::entityRows('work', $r['id'], $ctx) as $e) {
            $lists['work'][] = array('period' => $span($e['start_ym'], $e['end_ym']), 'start' => $e['start_ym'], 'end' => $e['end_ym'], 'employer' => $e['employer'],
                'position' => $e['position'], 'duties' => $e['duties'], 'leave_reason' => $e['leave_reason']);
        }
        foreach (self::entityRows('title', $r['id'], $ctx) as $e) {
            $lists['title'][] = array('title' => $e['title'], 'duty' => $e['duty'], 'level' => $e['level'], 'obtained' => $e['obtained_ym'], 'issuer' => $e['issuer']);
        }
        foreach (self::entityRows('achievement', $r['id'], $ctx) as $e) {
            $lists['achievement'][] = array('name' => $e['name'], 'type' => $dict('achievement_type', $e['type']), 'date' => $e['obtained_date'], 'issuer' => $e['issuer'],
                'certificate_no' => $e['certificate_no']);
        }
        foreach (array('education', 'work', 'title', 'achievement') as $k) {
            if (!isset($lists[$k])) {
                $lists[$k] = array();
            }
        }
        if ($ctx['family']) {
            $lists['family'] = array();
            foreach (self::entityRows('family', $r['id'], $ctx) as $e) {
                $lists['family'][] = array('relation' => $dict('relation', $e['relation']), 'name' => $e['name'], 'gender' => $dict('gender', $e['gender']), 'birth_date' => $e['birth_date'],
                    'employer' => $e['employer'], 'position' => $e['position'], 'phone' => $e['phone'], 'address' => $e['address']);
            }
        }
        $custom = array();
        $lists['custom'] = array();
        $values = \Omps\Engines\CustomFields::valuesOf('personnel', (int) $r['id']);
        foreach (\Omps\Engines\CustomFields::defs('personnel') as $d) {
            $text = \Omps\Engines\CustomFields::text($d, isset($values[$d['key']]) ? $values[$d['key']] : null);
            $custom[$d['key']] = $text;
            $lists['custom'][] = array('label' => $d['label'], 'value' => $text);
        }
        $images = array('photo' => null);
        if ($r['photo_file_id'] !== null) {
            $file = DB::table('files')->where('id', (int) $r['photo_file_id'])->where('status', 'normal')->first();
            if ($file) {
                $images['photo'] = Documents::image((string) $file['rel_path']);
            }
        }
        // fallback (no zip): the same data as plain blocks
        $fb = array(array('type' => 'title', 'text' => __('doc.person_title')), array('type' => 'meta', 'text' => $f['name'] . '  ' . $f['staff_no']));
        foreach (array('gender', 'birth_date', 'ethnicity', 'dept', 'position', 'hire_date', 'status', 'id_type', 'id_number', 'phone', 'email', 'address', 'hukou_address', 'remark') as $k) {
            if ($f[$k] !== '') {
                $fb[] = array('type' => 'item', 'tag' => __('staff.field.' . $k) . __('common.colon'), 'text' => $f[$k]);
            }
        }
        $groups = array('education' => 'staff.group.education', 'work' => 'staff.group.work', 'title' => 'staff.group.title', 'achievement' => 'staff.group.achievement',
            'family' => 'staff.group.family', 'custom' => 'doc.section.custom');
        foreach ($groups as $k => $label) {
            if (!empty($lists[$k])) {
                $fb[] = array('type' => 'h1', 'text' => __($label));
                foreach ($lists[$k] as $row) {
                    $fb[] = array('type' => 'item', 'text' => implode('  ', array_filter($row, 'strlen')));
                }
            }
        }
        $model = array('fields' => $f, 'blocks' => array(), 'lists' => $lists, 'images' => $images, 'custom' => $custom, 'fallback' => $fb);
        $label = $r['name'] . ' (' . $r['staff_no'] . ')';
        if (!$ctx['own']) {
            Audit::log($req->user, 'staff', 'person_doc_generated', 'person', $r['id'], $label, null, array('id_full' => $full, 'family' => $ctx['family']), $req->ip());
        }
        return Documents::download($req, 'person', $model, $tplId, __('doc.file_person', array('name' => $r['name'], 'no' => $r['staff_no'])), 'person', (int) $r['id']);
    }

    /**
     * Encrypt a new ID number; refuses one already used by another person. Any letters, digits or other
     * characters are accepted (up to 30), whatever the ID type: the format is not checked.
     */
    public static function idParts($type, $number, $personId)
    {
        $number = strtoupper(trim((string) $number));
        if ($number === '') {
            return array('id_number_enc' => null, 'id_number_hash' => null, 'id_number_mask' => null);
        }
        if (mb_strlen($number) > 30) {
            throw ValidationException::field('id_number', 'validation.max', array('field' => __('staff.field.id_number'), 'max' => 30));
        }
        $hash = Crypto::blindIndex($number);
        $dup = DB::table('personnel_profiles p')->active()->where('p.id_number_hash', $hash)->where('p.id', '<>', (int) $personId)->exists();
        if ($dup) {
            throw ValidationException::field('id_number', 'staff.err.id_taken');
        }
        return array('id_number_enc' => Crypto::encrypt($number), 'id_number_hash' => $hash, 'id_number_mask' => Crypto::mask($number));
    }

    /** Validate profile input (only the keys present). id_number: empty means "unchanged". */
    private static function cleanProfile(array $in, array $current)
    {
        $defs = Fields::profile();
        $sub = array();
        foreach ($defs as $k => $d) {
            if (array_key_exists($k, $in) && $k !== 'id_number') {
                $sub[$k] = $d;
            }
        }
        foreach (array('name', 'gender') as $req) {
            if (!isset($sub[$req])) {
                $in[$req] = $current[$req];
                $sub[$req] = $defs[$req];
            }
        }
        list($rules, $labels) = Fields::rulesAndLabels($sub);
        $v = Validator::check($in, $rules, $labels);
        foreach ($v as $k => $val) {
            $v[$k] = $val === '' ? null : $val;
        }
        if (isset($in['id_number']) && is_string($in['id_number']) && trim($in['id_number']) !== '') {
            $v['id_number'] = strtoupper(trim($in['id_number']));
            if (mb_strlen($v['id_number']) > 30) {
                throw ValidationException::field('id_number', 'validation.max', array('field' => __('staff.field.id_number'), 'max' => 30));
            }
        }
        return $v;
    }

    /** Values of the profile that differ from the current row (id_number compared by blind index). */
    private static function changedProfile(array $v, array $row)
    {
        $out = array();
        foreach ($v as $k => $val) {
            if ($k === 'id_number') {
                if (Crypto::blindIndex($val) !== $row['id_number_hash']) {
                    $out[$k] = $val;
                }
                continue;
            }
            if ((string) $row[$k] !== (string) $val) {
                $out[$k] = $val;
            }
        }
        return $out;
    }

    /** Turn changed profile values into column values (encrypting the ID number). */
    public static function profileColumns(array $changed, array $row)
    {
        $cols = $changed;
        if (array_key_exists('id_number', $changed)) {
            unset($cols['id_number']);
            $type = array_key_exists('id_type', $changed) ? $changed['id_type'] : $row['id_type'];
            $cols = array_merge($cols, self::idParts($type, $changed['id_number'], (int) $row['id']));
        }
        return $cols;
    }

    /** Validate one sub-record row. */
    public static function cleanEntityRow($entity, array $in)
    {
        $defs = Fields::entities();
        list($rules, $labels) = Fields::rulesAndLabels($defs[$entity]['fields']);
        try {
            $v = Validator::check($in, $rules, $labels);
        } catch (ValidationException $e) {
            throw new ValidationException(array($entity => implode(' ', $e->errors)));
        }
        foreach ($v as $k => $val) {
            if ($val === '') {
                $v[$k] = null;
            }
        }
        if (array_key_exists('graduated', $v)) {
            $v['graduated'] = empty($v['graduated']) ? 0 : 1;
        }
        return $v;
    }

    /** Column values for a sub-record (family phone / address encrypted; masked or hidden values mean "unchanged"). */
    public static function entityColumns($entity, array $v, $existing)
    {
        if ($entity !== 'family') {
            return $v;
        }
        $cols = $v;
        unset($cols['phone'], $cols['address']);
        if (array_key_exists('phone', $v)) {
            $ph = (string) $v['phone'];
            if ($existing && strpos($ph, '*') !== false) {
                // unchanged masked value
            } else {
                $cols['phone_enc'] = $ph === '' ? null : Crypto::encrypt($ph);
                $cols['phone_mask'] = $ph === '' ? null : Crypto::mask($ph, 3, 4);
            }
        }
        if (array_key_exists('address', $v)) {
            $ad = (string) $v['address'];
            if (!($existing && $ad === '******')) {
                $cols['address_enc'] = $ad === '' ? null : Crypto::encrypt($ad);
            }
        }
        return $cols;
    }

    /** Apply a sub-record change (used directly and when a request is approved). */
    public static function applyEntity($entity, $personId, $action, $recordId, array $cols, $userId)
    {
        $defs = Fields::entities();
        $table = $defs[$entity]['table'];
        if ($action === 'create') {
            $cols['person_id'] = (int) $personId;
            $cols['created_at'] = now();
            if ($entity === 'family') {
                $cols['created_by'] = $userId;
            }
            $cols['sort'] = (int) DB::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM ' . $table . ' WHERE person_id = ?', array((int) $personId));
            return DB::table($table)->insert($cols);
        }
        $exists = DB::table($table)->where('id', (int) $recordId)->where('person_id', (int) $personId)->first();
        if (!$exists) {
            throw HttpException::conflict('staff.err.record_gone');
        }
        if ($action === 'delete') {
            DB::table($table)->where('id', (int) $recordId)->delete();
            return (int) $recordId;
        }
        $cols['updated_at'] = now();
        if ($entity === 'family') {
            $cols['updated_by'] = $userId;
        }
        DB::table($table)->where('id', (int) $recordId)->update($cols);
        return (int) $recordId;
    }

    /**
     * Compare submitted rows of one entity with the stored rows.
     * @return array list of [action, recordId, cleanValues, beforeValues]
     */
    public static function diffEntity($entity, $personId, array $rows, array $ctx)
    {
        $current = array();
        foreach (self::entityRows($entity, $personId, array_merge($ctx, array('family_full' => true))) as $r) {
            $current[$r['id']] = $r;
        }
        $seen = array();
        $changes = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rid = isset($row['id']) && $row['id'] !== '' && $row['id'] !== null ? (int) $row['id'] : 0;
            unset($row['id']);
            $v = self::cleanEntityRow($entity, $row);
            if ($rid) {
                if (!isset($current[$rid])) {
                    throw HttpException::conflict('staff.err.record_gone');
                }
                $seen[$rid] = true;
                $before = $current[$rid];
                unset($before['id']);
                $diff = false;
                foreach ($v as $k => $val) {
                    $old = isset($before[$k]) ? $before[$k] : '';
                    if (is_bool($old)) {
                        $old = $old ? 1 : 0;
                    }
                    if ($entity === 'family' && (($k === 'phone' && strpos((string) $val, '*') !== false) || ($k === 'address' && $val === '******'))) {
                        continue;
                    }
                    if ((string) $old !== (string) $val) {
                        $diff = true;
                    }
                }
                if ($diff) {
                    $changes[] = array('update', $rid, $v, $before);
                }
            } else {
                $changes[] = array('create', null, $v, null);
            }
        }
        foreach ($current as $rid => $r) {
            if (!isset($seen[$rid])) {
                $before = $r;
                unset($before['id']);
                $changes[] = array('delete', $rid, array(), $before);
            }
        }
        return $changes;
    }

    private static function snapshot($personId, $userId)
    {
        $r = self::row($personId);
        unset($r['id_number_enc'], $r['id_number_hash']);
        DB::table('data_versions')->insert(array('org_id' => (int) config('app.org_id', 1), 'entity_type' => 'person', 'entity_id' => (int) $personId,
            'version' => (int) $r['version'], 'snapshot' => json_encode($r, JSON_UNESCAPED_UNICODE), 'created_by' => $userId, 'created_at' => now()));
    }

    /** Direct edit by an administrator (profile and any sub-records sent). */
    public static function saveAdmin(Request $req, $id, array $in)
    {
        list($p, $ctx) = self::access($req, $id);
        if (!$ctx['admin_edit']) {
            throw HttpException::forbidden();
        }
        $row = self::row($p['id']);
        if (isset($in['version']) && (int) $in['version'] !== (int) $row['version']) {
            throw HttpException::conflict('error.stale');
        }
        $profileIn = isset($in['profile']) && is_array($in['profile']) ? $in['profile'] : array();
        $changed = $profileIn ? self::changedProfile(self::cleanProfile($profileIn, $row), $row) : array();
        $cols = self::profileColumns($changed, $row);
        $entityChanges = array();
        foreach (Fields::entities() as $e => $def) {
            if (!isset($in[$e]) || !is_array($in[$e])) {
                continue;
            }
            if ($e === 'family' && !$ctx['family_edit']) {
                continue;
            }
            $entityChanges[$e] = self::diffEntity($e, $row['id'], $in[$e], $ctx);
        }
        // custom fields (phase 7): only the keys sent are changed
        $cfValues = isset($in['custom']) && is_array($in['custom']) ? \Omps\Engines\CustomFields::validate('personnel', $in['custom'], true) : array();
        $cfBefore = $cfValues ? \Omps\Engines\CustomFields::valuesOf('personnel', (int) $row['id']) : array();
        $uid = $req->userId();
        DB::transaction(function () use ($row, $cols, $entityChanges, $uid, $cfValues) {
            \Omps\Engines\CustomFields::save('personnel', (int) $row['id'], $cfValues);
            $upd = array_merge($cols, array('updated_at' => now(), 'updated_by' => $uid));
            if (DB::table('personnel_profiles')->where('id', (int) $row['id'])->where('version', (int) $row['version'])->increment('version', 1, $upd) === 0) {
                throw HttpException::conflict('error.stale');
            }
            foreach ($entityChanges as $e => $list) {
                foreach ($list as $c) {
                    self::applyEntity($e, $row['id'], $c[0], $c[1], self::entityColumns($e, $c[2], $c[0] !== 'create'), $uid);
                }
            }
            if (array_key_exists('name', $cols)) {
                DB::table('users')->where('person_id', (int) $row['id'])->update(array('display_name' => $cols['name']));
            }
            self::snapshot($row['id'], $uid);
        });
        $label = $row['name'] . ' (' . $row['staff_no'] . ')';
        list($cb, $ca) = \Omps\Engines\CustomFields::diff('personnel', $cfBefore, $cfValues);
        if ($changed || $ca) {
            list($b, $a) = $changed ? self::auditPair($changed, $row) : array(array(), array());
            Audit::change($req, 'staff', 'person_updated', 'person', $row['id'], $label, array_merge($b, $cb), array_merge($a, $ca));
        }
        foreach ($entityChanges as $e => $list) {
            foreach ($list as $c) {
                Audit::change($req, 'staff', 'record_' . $c[0], 'person_' . $e, $row['id'], $label, self::maskSensitive($e, $c[3]), self::maskSensitive($e, $c[2]));
            }
        }
        return array('changed' => count($changed) + count($ca) + array_sum(array_map('count', $entityChanges)));
    }

    /** Before/after for the audit log; ID numbers only as masks. */
    public static function auditPair(array $changed, array $row)
    {
        $b = array();
        $a = array();
        foreach ($changed as $k => $v) {
            if ($k === 'id_number') {
                $b[$k] = $row['id_number_mask'];
                $a[$k] = Crypto::mask($v);
                continue;
            }
            $b[$k] = $row[$k];
            $a[$k] = $v;
        }
        return array($b, $a);
    }

    public static function maskSensitive($entity, $data)
    {
        if (!is_array($data) || $entity !== 'family') {
            return $data;
        }
        if (isset($data['phone']) && $data['phone'] !== '' && $data['phone'] !== null && strpos((string) $data['phone'], '*') === false) {
            $data['phone'] = Crypto::mask($data['phone'], 3, 4);
        }
        if (isset($data['address']) && $data['address'] !== '' && $data['address'] !== null) {
            $data['address'] = '******';
        }
        return $data;
    }

    /**
     * Self-service: the person edits their own record. Per field policy: forbidden fields are refused,
     * direct ones apply now, the rest become change requests for the reviewer.
     * Whole-unit roles (unit head, super) have no reviewer above them: their changes apply directly.
     */
    public static function saveSelf(Request $req, array $in)
    {
        $pid = $req->user['person_id'];
        if ($pid === null) {
            throw HttpException::notFound();
        }
        list($p, $ctx) = self::access($req, $pid);
        $row = self::row($pid);
        $profileIn = isset($in['profile']) && is_array($in['profile']) ? $in['profile'] : array();
        $changed = $profileIn ? self::changedProfile(self::cleanProfile($profileIn, $row), $row) : array();
        $direct = array();
        $review = array();
        foreach ($changed as $k => $v) {
            $pol = Fields::policy('profile', $k);
            if ($pol === 'forbid') {
                throw ValidationException::field($k, 'staff.err.not_self_editable', array('field' => __(Fields::profile()[$k]['label'])));
            }
            if ($pol === 'direct' || $ctx['review_direct']) {
                $direct[$k] = $v;
            } else {
                $review[$k] = $v;
            }
        }
        $directCols = self::profileColumns($direct, $row);
        $reviewCols = self::profileColumns($review, $row);
        $entityDirect = array();
        $entityReview = array();
        foreach (Fields::entities() as $e => $def) {
            if (!isset($in[$e]) || !is_array($in[$e])) {
                continue;
            }
            $pol = Fields::policy($e, '*');
            if ($pol === 'forbid') {
                continue;
            }
            foreach (self::diffEntity($e, $pid, $in[$e], $ctx) as $c) {
                if ($pol === 'direct' || $ctx['review_direct']) {
                    $entityDirect[] = array($e, $c);
                } else {
                    $entityReview[] = array($e, $c);
                }
            }
        }
        $uid = $req->userId();
        $label = $row['name'] . ' (' . $row['staff_no'] . ')';
        DB::transaction(function () use ($row, $pid, $directCols, $reviewCols, $review, $entityDirect, $entityReview, $uid) {
            if ($directCols) {
                DB::table('personnel_profiles')->where('id', (int) $pid)->increment('version', 1, array_merge($directCols, array('updated_at' => now(), 'updated_by' => $uid)));
            }
            foreach ($entityDirect as $item) {
                list($e, $c) = $item;
                self::applyEntity($e, $pid, $c[0], $c[1], self::entityColumns($e, $c[2], $c[0] !== 'create'), $uid);
            }
            if ($review) {
                list($b, $a) = self::auditPair($review, $row);
                ChangeRequests::create($pid, 'profile', null, 'update', $b, array('display' => $a, 'columns' => self::sealColumns($reviewCols)), $uid);
            }
            foreach ($entityReview as $item) {
                list($e, $c) = $item;
                ChangeRequests::create($pid, $e, $c[1], $c[0], $c[3], array('display' => self::maskSensitive($e, $c[2]), 'columns' => self::sealColumns(self::entityColumns($e, $c[2], $c[0] !== 'create'))), $uid);
            }
            if ($directCols || $entityDirect) {
                self::snapshot($pid, $uid);
            }
        });
        if ($direct) {
            list($b, $a) = self::auditPair($direct, $row);
            Audit::change($req, 'staff', 'person_self_updated', 'person', $pid, $label, $b, $a);
        }
        foreach ($entityDirect as $item) {
            Audit::change($req, 'staff', 'record_' . $item[1][0], 'person_' . $item[0], $pid, $label, self::maskSensitive($item[0], $item[1][3]), self::maskSensitive($item[0], $item[1][2]));
        }
        if ($review || $entityReview) {
            Audit::op($req->user, 'staff', 'change_submitted', 'person', $pid, $label, array('profile' => array_keys($review), 'records' => count($entityReview)), $req->ip());
        }
        return array('applied' => count($direct) + count($entityDirect), 'submitted' => ($review ? 1 : 0) + count($entityReview));
    }

    /** Request payloads keep column values; nothing secret is stored in plain text (encrypted values stay encrypted). */
    private static function sealColumns(array $cols)
    {
        return $cols;
    }

    /** Change history of a record (from the audit log). */
    public static function history(Request $req, $id)
    {
        list($p) = self::access($req, $id);
        $rows = DB::select("SELECT created_at, username, action, target_type, before_data, after_data FROM audit_logs
            WHERE target_id = ? AND (target_type = 'person' OR target_type LIKE 'person\\_%') AND action <> 'family_viewed' ORDER BY id DESC LIMIT 200", array((int) $p['id']));
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('time' => (string) $r['created_at'], 'user' => str_or_empty($r['username']), 'action' => \Omps\Core\Lang::has('log.action.' . $r['action']) ? __('log.action.' . $r['action']) : (string) $r['action'],
                'entity' => (string) $r['target_type'], 'before' => $r['before_data'] === null ? null : json_decode((string) $r['before_data'], true),
                'after' => $r['after_data'] === null ? null : json_decode((string) $r['after_data'], true));
        }
        return $out;
    }
}
