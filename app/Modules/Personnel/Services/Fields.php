<?php
namespace Omps\Modules\Personnel\Services;

use Omps\Database\DB;

/**
 * Field definitions of a staff record (phase 2): validation rules, labels, dictionaries,
 * and the self-edit policy from profile_field_policies.
 */
class Fields
{
    const YM = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /** Main record fields that can be edited (dept, status and staff number change through their own actions). */
    public static function profile()
    {
        return array(
            'name' => array('rules' => 'required|string|max:50', 'label' => 'staff.field.name'),
            'gender' => array('rules' => 'required|in:male,female', 'label' => 'staff.field.gender', 'dict' => 'gender'),
            'birth_date' => array('rules' => 'nullable|date', 'label' => 'staff.field.birth_date'),
            'ethnicity' => array('rules' => 'nullable|string|max:30', 'label' => 'staff.field.ethnicity'),
            'id_type' => array('rules' => 'nullable|in:resident_id,passport,hk_macao_pass,other', 'label' => 'staff.field.id_type', 'dict' => 'id_type'),
            'id_number' => array('rules' => 'nullable|string|max:30', 'label' => 'staff.field.id_number', 'sensitive' => true),
            'phone' => array('rules' => array('nullable', 'string', 'regex:/^[0-9+\- ]{5,30}$/'), 'label' => 'staff.field.phone'),
            'email' => array('rules' => 'nullable|email|max:100', 'label' => 'staff.field.email'),
            'address' => array('rules' => 'nullable|string|max:200', 'label' => 'staff.field.address'),
            'hukou_address' => array('rules' => 'nullable|string|max:200', 'label' => 'staff.field.hukou_address'),
            'position' => array('rules' => 'nullable|string|max:100', 'label' => 'staff.field.position'),
            'hire_date' => array('rules' => 'nullable|date', 'label' => 'staff.field.hire_date'),
            'remark' => array('rules' => 'nullable|string|max:2000', 'label' => 'staff.field.remark'),
        );
    }

    /** Repeatable sub-records: entity => table and fields. */
    public static function entities()
    {
        $ym = array('nullable', 'string', 'regex:' . self::YM);
        return array(
            'education' => array('table' => 'personnel_education', 'label' => 'staff.group.education', 'fields' => array(
                'start_ym' => array('rules' => $ym, 'label' => 'staff.edu.start_ym'),
                'end_ym' => array('rules' => $ym, 'label' => 'staff.edu.end_ym'),
                'school' => array('rules' => 'required|string|max:100', 'label' => 'staff.edu.school'),
                'country_region' => array('rules' => 'nullable|string|max:50', 'label' => 'staff.edu.country_region'),
                'major' => array('rules' => 'nullable|string|max:100', 'label' => 'staff.edu.major'),
                'degree_level' => array('rules' => 'nullable|in:high_school,junior_college,bachelor,postgraduate', 'label' => 'staff.edu.degree_level', 'dict' => 'degree_level'),
                'degree' => array('rules' => 'nullable|in:none,bachelor,master,doctor', 'label' => 'staff.edu.degree', 'dict' => 'degree'),
                'graduated' => array('rules' => 'nullable|bool', 'label' => 'staff.edu.graduated', 'bool' => true),
                'certificate_no' => array('rules' => 'nullable|string|max:50', 'label' => 'staff.edu.certificate_no'),
            )),
            'work' => array('table' => 'personnel_work_experience', 'label' => 'staff.group.work', 'fields' => array(
                'start_ym' => array('rules' => $ym, 'label' => 'staff.work.start_ym'),
                'end_ym' => array('rules' => $ym, 'label' => 'staff.work.end_ym'),
                'employer' => array('rules' => 'required|string|max:100', 'label' => 'staff.work.employer'),
                'position' => array('rules' => 'nullable|string|max:100', 'label' => 'staff.work.position'),
                'duties' => array('rules' => 'nullable|string|max:500', 'label' => 'staff.work.duties'),
                'leave_reason' => array('rules' => 'nullable|string|max:200', 'label' => 'staff.work.leave_reason'),
            )),
            'title' => array('table' => 'personnel_titles', 'label' => 'staff.group.title', 'fields' => array(
                'title' => array('rules' => 'required|string|max:100', 'label' => 'staff.title.title'),
                'duty' => array('rules' => 'nullable|string|max:100', 'label' => 'staff.title.duty'),
                'level' => array('rules' => 'nullable|string|max:50', 'label' => 'staff.title.level'),
                'obtained_ym' => array('rules' => $ym, 'label' => 'staff.title.obtained_ym'),
                'issuer' => array('rules' => 'nullable|string|max:100', 'label' => 'staff.title.issuer'),
            )),
            'achievement' => array('table' => 'personnel_achievements', 'label' => 'staff.group.achievement', 'fields' => array(
                'name' => array('rules' => 'required|string|max:150', 'label' => 'staff.ach.name'),
                'type' => array('rules' => 'nullable|in:paper,patent,software,award,other', 'label' => 'staff.ach.type', 'dict' => 'achievement_type'),
                'obtained_date' => array('rules' => 'nullable|date', 'label' => 'staff.ach.obtained_date'),
                'issuer' => array('rules' => 'nullable|string|max:100', 'label' => 'staff.ach.issuer'),
                'certificate_no' => array('rules' => 'nullable|string|max:50', 'label' => 'staff.ach.certificate_no'),
            )),
            'family' => array('table' => 'personnel_families', 'label' => 'staff.group.family', 'fields' => array(
                'name' => array('rules' => 'required|string|max:50', 'label' => 'family.field.name'),
                'relation' => array('rules' => 'required|in:spouse,father,mother,son,daughter,other', 'label' => 'family.field.relation', 'dict' => 'relation'),
                'gender' => array('rules' => 'nullable|in:male,female', 'label' => 'family.field.gender', 'dict' => 'gender'),
                'birth_date' => array('rules' => 'nullable|date', 'label' => 'family.field.birth_date'),
                'employer' => array('rules' => 'nullable|string|max:100', 'label' => 'family.field.employer'),
                'position' => array('rules' => 'nullable|string|max:100', 'label' => 'family.field.position'),
                'phone' => array('rules' => array('nullable', 'string', 'regex:/^[0-9+\- ]{5,30}$/'), 'label' => 'family.field.phone', 'sensitive' => true),
                'address' => array('rules' => 'nullable|string|max:200', 'label' => 'family.field.address', 'sensitive' => true),
            )),
        );
    }

    public static function rulesAndLabels(array $defs)
    {
        $rules = array();
        $labels = array();
        foreach ($defs as $k => $d) {
            $rules[$k] = $d['rules'];
            $labels[$k] = $d['label'];
        }
        return array($rules, $labels);
    }

    /** Self-edit policy for (entity, field): forbid | direct | review. */
    public static function policy($entity, $field)
    {
        static $cache = null;
        if ($cache === null) {
            $cache = array();
            foreach (DB::table('profile_field_policies')->get() as $p) {
                $cache[$p['entity'] . '|' . $p['field_key']] = (string) $p['self_edit'];
            }
        }
        if (isset($cache[$entity . '|' . $field])) {
            return $cache[$entity . '|' . $field];
        }
        if (isset($cache[$entity . '|*'])) {
            return $cache[$entity . '|*'];
        }
        return 'review';
    }

    /** Field metadata for the front end (labels as keys, dictionaries, policies for the viewer's own record). */
    public static function meta()
    {
        $profile = array();
        foreach (self::profile() as $k => $d) {
            $profile[$k] = array('label' => $d['label'], 'dict' => isset($d['dict']) ? $d['dict'] : null, 'policy' => self::policy('profile', $k));
        }
        $profile['photo_file_id'] = array('label' => 'staff.field.photo', 'dict' => null, 'policy' => self::policy('profile', 'photo_file_id'));
        $entities = array();
        foreach (self::entities() as $e => $def) {
            $fields = array();
            foreach ($def['fields'] as $k => $d) {
                $fields[$k] = array('label' => $d['label'], 'dict' => isset($d['dict']) ? $d['dict'] : null, 'bool' => !empty($d['bool']));
            }
            $entities[$e] = array('label' => $def['label'], 'policy' => self::policy($e, '*'), 'fields' => $fields);
        }
        $dicts = array();
        $base = require OMPS_ROOT . '/database/seeds/base.php';
        foreach ($base['dictionaries'] as $code => $items) {
            $dicts[$code] = $items;
        }
        return array('profile' => $profile, 'entities' => $entities, 'dicts' => $dicts);
    }
}
