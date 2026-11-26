<?php
namespace Omps\Modules\System\Controllers;

use Omps\Core\Controller;
use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\Settings;
use Omps\Core\Validator;
use Omps\Engines\Audit;
use Omps\Modules\System\Services\HolidayService;
use Omps\Modules\System\Services\LanguageService;

/**
 * Unit information, default language and security settings (super admin).
 */
class SettingsController extends Controller
{
    const LIMITS = array(
        'login_max_failures' => array(1, 20),
        'login_lock_minutes' => array(1, 1440),
        'session_idle_minutes' => array(5, 1440),
        'session_absolute_hours' => array(1, 72),
        'password_min_length' => array(6, 32),
    );

    public function page(Request $req)
    {
        return $this->renderPage($req, 'System::settings', 'menu.settings', array(), array('settings'));
    }

    public function show(Request $req)
    {
        $all = Settings::all();
        $langs = array();
        foreach (Lang::enabledCodes() as $c) {
            $langs[] = array('code' => $c, 'name' => LanguageService::name($c));
        }
        $out = array('unit_name' => (string) $all['unit_name'], 'unit_address' => (string) $all['unit_address'], 'unit_phone' => (string) $all['unit_phone'],
            'default_language' => Lang::defaultCode(), 'languages' => $langs, 'limits' => self::LIMITS,
            'work_start' => (string) Settings::get('work_start', '08:30'), 'work_end' => (string) Settings::get('work_end', '17:30'),
            'work_days' => array_values(array_filter(array_map('intval', explode(',', (string) Settings::get('work_days', '1,2,3,4,5'))))));
        foreach (array_keys(self::LIMITS) as $k) {
            $out[$k] = (int) $all[$k];
        }
        return $out;
    }

    public function update(Request $req)
    {
        $rules = array('unit_name' => 'required|string|max:100', 'unit_address' => 'nullable|string|max:200', 'unit_phone' => 'nullable|string|max:50', 'default_language' => 'required|string');
        $labels = array('unit_name' => 'settings.unit_name', 'unit_address' => 'settings.unit_address', 'unit_phone' => 'settings.unit_phone', 'default_language' => 'settings.default_language');
        foreach (self::LIMITS as $k => $lim) {
            $rules[$k] = 'required|int|min:' . $lim[0] . '|max:' . $lim[1];
            $labels[$k] = 'settings.' . $k;
        }
        $rules['work_start'] = array('nullable', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/');
        $rules['work_end'] = array('nullable', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/');
        $labels['work_start'] = 'settings.work_start';
        $labels['work_end'] = 'settings.work_end';
        $v = Validator::check($req->body, $rules, $labels);
        // work time for reminders (design §13.3): kept as before when not sent
        foreach (array('work_start' => '08:30', 'work_end' => '17:30') as $k => $def) {
            if (!isset($v[$k]) || $v[$k] === null || $v[$k] === '') {
                $v[$k] = (string) Settings::get($k, $def);
            }
        }
        if (\Omps\Engines\WorkCalendar::minutes($v['work_end']) <= \Omps\Engines\WorkCalendar::minutes($v['work_start'])) {
            throw \Omps\Core\ValidationException::field('work_end', 'settings.err.work_hours');
        }
        $days = array();
        $rawDays = isset($req->body['work_days']) && is_array($req->body['work_days']) ? $req->body['work_days'] : explode(',', (string) Settings::get('work_days', '1,2,3,4,5'));
        foreach ($rawDays as $d) {
            if ((is_int($d) || (is_string($d) && ctype_digit(trim($d)))) && (int) $d >= 1 && (int) $d <= 7) {
                $days[(int) $d] = (int) $d;
            }
        }
        if (!$days) {
            throw \Omps\Core\ValidationException::field('work_days', 'settings.err.work_days');
        }
        ksort($days);
        $v['work_days'] = implode(',', $days);
        $before = Settings::all();
        foreach (array('work_start' => '08:30', 'work_end' => '17:30', 'work_days' => '1,2,3,4,5') as $k => $def) {
            if (!array_key_exists($k, $before)) {
                $before[$k] = $def;
            }
        }
        $beforeLang = Lang::defaultCode();
        if ($v['default_language'] !== $beforeLang && !LanguageService::setDefault($v['default_language'])) {
            throw HttpException::bad('settings.err.language_disabled');
        }
        $changed = array();
        $old = array();
        foreach ($v as $k => $val) {
            if ($k === 'default_language') {
                continue;
            }
            $val = $val === null ? '' : $val;
            if ((string) $before[$k] !== (string) $val) {
                Settings::set($k, $val, $req->userId());
                $old[$k] = $before[$k];
                $changed[$k] = $val;
            }
        }
        if ($v['default_language'] !== $beforeLang) {
            $old['default_language'] = $beforeLang;
            $changed['default_language'] = $v['default_language'];
        }
        if ($changed) {
            Audit::change($req, 'settings', 'settings_changed', 'settings', null, null, $old, $changed);
        }
        return array('ok' => true);
    }

    public function holidays(Request $req)
    {
        return HolidayService::index($req->int('year'));
    }

    public function holidayStore(Request $req)
    {
        return HolidayService::store($req, $req->body);
    }

    public function holidayUpdate(Request $req)
    {
        return HolidayService::update($req, $this->idParam($req), $req->body);
    }

    public function holidayDestroy(Request $req)
    {
        return HolidayService::destroy($req, $this->idParam($req));
    }
}
