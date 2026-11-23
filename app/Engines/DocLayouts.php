<?php
namespace Omps\Engines;

use Omps\Core\DocxBuilder;
use Omps\Core\Lang;

/**
 * Built-in Word layouts (phase 8), written in the current language with placeholders in the same
 * language, then filled like any uploaded template. Downloaded as "sample template" they are the
 * starting point for a unit's own templates.
 *  - report: A4, 仿宋 three-size body with a 28 pt line pitch, headings in 黑体 / 楷体, unit name in
 *    the header and "— n —" page numbers (the usual layout of Chinese official reports).
 *  - person: a staff registration form; basic data with the photo, then one table per section.
 */
class DocLayouts
{
    private static function ph($type, $key, $list = null)
    {
        return '{{' . Documents::phName($type, $key, $list) . '}}';
    }

    private static function cjk()
    {
        return strpos(Lang::locale(), 'zh') === 0;
    }

    public static function report()
    {
        $cjk = self::cjk();
        $body = $cjk ? 16 : 12;
        $line = $cjk ? 28 : 20;
        $b = new DocxBuilder(__('doc.type.report'));
        $b->margins(2098, 1531, 1984, 1588);
        $b->style(array('id' => 'Normal', 'name' => 'Normal', 'font' => 'FangSong', 'size' => $body, 'line' => $line))
            ->style(array('id' => 'OmpsTitle', 'name' => __('doc.style.title'), 'font' => 'SimSun', 'bold' => true, 'size' => $cjk ? 22 : 18, 'align' => 'center', 'line' => $cjk ? 36 : 28, 'after' => 6))
            ->style(array('id' => 'OmpsSub', 'name' => __('doc.style.sub'), 'font' => 'KaiTi', 'size' => $body, 'align' => 'center', 'line' => $line))
            ->style(array('id' => 'OmpsMeta', 'name' => __('doc.style.meta'), 'font' => 'FangSong', 'size' => $cjk ? 12 : 10, 'color' => '595959', 'align' => 'center', 'line' => $cjk ? 20 : 16, 'after' => 6))
            ->style(array('id' => 'OmpsH1', 'name' => __('doc.style.h1'), 'font' => 'SimHei', 'size' => $body, 'indent' => 2, 'line' => $line, 'before' => 6, 'keep' => true))
            ->style(array('id' => 'OmpsH2', 'name' => __('doc.style.h2'), 'font' => 'KaiTi', 'bold' => true, 'size' => $body, 'indent' => 2, 'line' => $line, 'keep' => true))
            ->style(array('id' => 'OmpsNote', 'name' => __('doc.style.note'), 'font' => 'FangSong', 'size' => $cjk ? 14 : 11, 'color' => '595959', 'indent' => 2, 'line' => $cjk ? 24 : 18))
            ->style(array('id' => 'OmpsItem', 'name' => __('doc.style.item'), 'font' => 'FangSong', 'size' => $body, 'indent' => 2, 'line' => $line))
            ->style(array('id' => 'OmpsBody', 'name' => __('doc.style.body'), 'font' => 'FangSong', 'size' => $body, 'indent' => 2, 'line' => $line))
            ->style(array('id' => 'OmpsSign', 'name' => __('doc.style.sign'), 'font' => 'FangSong', 'size' => $body, 'align' => 'right', 'line' => $line));
        $b->header(self::ph('report', 'unit_name'))->pageNumbers();
        $b->p(self::ph('report', 'title'), 'OmpsTitle')
            ->p(self::ph('report', 'period'), 'OmpsSub')
            ->p(self::ph('report', 'conditions'), 'OmpsMeta')
            ->p(self::ph('report', 'intro'), 'OmpsBody')
            ->p(self::ph('report', 'body'), 'OmpsBody')
            ->p(self::ph('report', 'outro'), 'OmpsBody')
            ->p('', 'OmpsBody')
            ->p(self::ph('report', 'unit_name'), 'OmpsSign')
            ->p(self::ph('report', 'today'), 'OmpsSign')
            ->p(self::ph('report', 'as_of'), 'OmpsMeta', array('align' => 'right'));
        return $b->bytes();
    }

    /**
     * @param array|null $model the person model: sections without data the viewer may see (family)
     *                          and the custom section without fields are left out; null = all
     */
    public static function person($model = null)
    {
        $b = new DocxBuilder(__('doc.type.person'));
        $b->margins(1304, 1134, 1134, 1134);
        $b->style(array('id' => 'Normal', 'name' => 'Normal', 'font' => 'SimSun', 'size' => 10.5))
            ->style(array('id' => 'OmpsTitle', 'name' => __('doc.style.title'), 'font' => 'SimSun', 'bold' => true, 'size' => 18, 'align' => 'center', 'after' => 6))
            ->style(array('id' => 'OmpsMeta', 'name' => __('doc.style.meta'), 'font' => 'SimSun', 'size' => 10.5, 'align' => 'right', 'after' => 4))
            ->style(array('id' => 'OmpsGap', 'name' => __('doc.style.gap'), 'size' => 5, 'line' => 5));
        $b->header(self::ph('person', 'unit_name'))->pageNumbers();
        $b->p(__('doc.person_title'), 'OmpsTitle');
        $b->p(__('doc.filled_on', array('date' => self::ph('person', 'today'))), 'OmpsMeta');

        $W = $b->width();
        $photo = 1701;
        $lab = 1134;
        $val = (int) floor(($W - $photo - 3 * $lab) / 3);
        $last = $W - $photo - 3 * $lab - 2 * $val;
        $cols = array($lab, $val, $lab, $val, $lab, $last, $photo);
        $L = function ($key) {
            return array('text' => __($key), 'label' => true);
        };
        $V = function ($key, $span = 1) {
            return array('text' => self::ph('person', $key), 'span' => $span);
        };
        $h = 470;
        $rows = array(
            array('h' => $h, 'cells' => array($L('staff.field.name'), $V('name'), $L('staff.field.gender'), $V('gender'), $L('staff.field.birth_date'), $V('birth_date'),
                array('text' => self::ph('person', 'photo'), 'merge' => 'restart', 'align' => 'center'))),
            array('h' => $h, 'cells' => array($L('staff.field.ethnicity'), $V('ethnicity'), $L('staff.field.staff_no'), $V('staff_no'), $L('staff.field.hire_date'), $V('hire_date'), array('merge' => 'continue'))),
            array('h' => $h, 'cells' => array($L('staff.field.dept'), $V('dept'), $L('staff.field.position'), $V('position'), $L('staff.field.status'), $V('status'), array('merge' => 'continue'))),
            array('h' => $h, 'cells' => array($L('staff.field.id_type'), $V('id_type'), $L('staff.field.id_number'), $V('id_number', 3), array('merge' => 'continue'))),
            array('h' => $h, 'cells' => array($L('staff.field.phone'), $V('phone'), $L('staff.field.email'), $V('email', 4))),
            array('h' => $h, 'cells' => array($L('staff.field.address'), $V('address', 6))),
            array('h' => $h, 'cells' => array($L('staff.field.hukou_address'), $V('hukou_address', 6))),
            array('h' => 760, 'cells' => array($L('staff.field.remark'), $V('remark', 6))),
        );
        $b->table($cols, $rows);

        $sections = array(
            'education' => array('staff.group.education', array(array('period', 'doc.col.period', 3), array('school', 'staff.edu.school', 4), array('major', 'staff.edu.major', 3),
                array('degree_level', 'staff.edu.degree_level', 2), array('degree', 'staff.edu.degree', 2))),
            'work' => array('staff.group.work', array(array('period', 'doc.col.period', 3), array('employer', 'staff.work.employer', 4), array('position', 'staff.work.position', 3),
                array('duties', 'staff.work.duties', 5))),
            'title' => array('staff.group.title', array(array('title', 'staff.title.title', 3), array('duty', 'staff.title.duty', 3), array('level', 'staff.title.level', 3),
                array('obtained', 'staff.title.obtained_ym', 2), array('issuer', 'staff.title.issuer', 3))),
            'achievement' => array('staff.group.achievement', array(array('name', 'staff.ach.name', 4), array('type', 'staff.ach.type', 2), array('date', 'staff.ach.obtained_date', 2),
                array('issuer', 'staff.ach.issuer', 3), array('certificate_no', 'staff.ach.certificate_no', 3))),
            'family' => array('staff.group.family', array(array('relation', 'family.field.relation', 2), array('name', 'family.field.name', 2), array('birth_date', 'family.field.birth_date', 2),
                array('employer', 'family.field.employer', 4), array('position', 'family.field.position', 2), array('phone', 'family.field.phone', 3))),
            'custom' => array('doc.section.custom', array(array('label', 'doc.col.item', 1), array('value', 'doc.col.content', 3))),
        );
        foreach ($sections as $list => $s) {
            if ($model !== null && (!isset($model['lists'][$list]) || ($list === 'custom' && !$model['lists'][$list]))) {
                continue;
            }
            $weights = 0;
            foreach ($s[1] as $c) {
                $weights += $c[2];
            }
            $widths = array();
            foreach ($s[1] as $i => $c) {
                $widths[] = $i === count($s[1]) - 1 ? $W - array_sum($widths) : (int) floor($W * $c[2] / $weights);
            }
            $head = array();
            $data = array();
            foreach ($s[1] as $c) {
                $head[] = array('text' => __($c[1]), 'label' => true);
                $data[] = array('text' => self::ph('person', $c[0], $list), 'align' => 'center');
            }
            $b->p('', 'OmpsGap');
            $b->table($widths, array(
                array('h' => 420, 'header' => true, 'cells' => array(array('text' => __($s[0]), 'span' => count($s[1]), 'bold' => true, 'fill' => 'D9D9D9'))),
                array('h' => 420, 'header' => true, 'cells' => $head),
                array('h' => 420, 'cells' => $data),
            ));
        }
        $b->p('', 'OmpsGap');
        return $b->bytes();
    }
}
