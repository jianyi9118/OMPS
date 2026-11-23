<?php
namespace Omps\Modules\System\Controllers;

use Omps\Core\Controller;
use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Database\DB;

/**
 * Read-only views of the operation log, audit log and login log. Codes are translated
 * into the viewer's language; recorded values are shown as they were.
 */
class LogController extends Controller
{
    public function page(Request $req)
    {
        return $this->renderPage($req, 'System::logs', 'menu.logs', array(), array('logs'));
    }

    public function index(Request $req)
    {
        $type = (string) $req->param('type');
        $tables = array('operation' => 'operation_logs', 'audit' => 'audit_logs', 'login' => 'login_logs');
        if (!isset($tables[$type])) {
            throw HttpException::notFound();
        }
        list($page, $size) = $this->pageArgs($req);
        $q = DB::table($tables[$type]);
        $from = $req->str('from');
        $to = $req->str('to');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $q->where('created_at', '>=', $from . ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $q->where('created_at', '<=', $to . ' 23:59:59');
        }
        if ($req->str('user') !== '') {
            $q->whereLikeAny(array('username'), $req->str('user'));
        }
        if ($type !== 'login' && $req->str('module') !== '') {
            $q->where('module', $req->str('module'));
        }
        if ($type === 'login' && $req->str('result') !== '') {
            $q->where('result', $req->str('result'));
        }
        $res = $q->orderBy('id', 'DESC')->paginate($page, $size);
        foreach ($res['rows'] as &$r) {
            $r = $this->present($type, $r);
        }
        unset($r);
        $res['modules'] = array();
        if ($type !== 'login') {
            foreach (DB::select('SELECT DISTINCT module FROM ' . $tables[$type] . ' ORDER BY module') as $m) {
                $res['modules'][] = array('code' => (string) $m['module'], 'name' => $this->term('log.module.', (string) $m['module']));
            }
        }
        return $res;
    }

    private function term($prefix, $code)
    {
        return Lang::has($prefix . $code) ? __($prefix . $code) : $code;
    }

    private function present($type, array $r)
    {
        $out = array('id' => (int) $r['id'], 'time' => (string) $r['created_at'], 'user' => str_or_empty($r['username']), 'ip' => str_or_empty($r['ip']));
        if ($type === 'login') {
            $out['result'] = (string) $r['result'];
            $out['result_name'] = __('log.login.' . $r['result']);
            $out['reason'] = $r['reason'] === null ? '' : $this->term('log.reason.', (string) $r['reason']);
            $out['agent'] = str_or_empty($r['user_agent']);
            return $out;
        }
        $out['module'] = $this->term('log.module.', (string) $r['module']);
        $out['action'] = $this->term('log.action.', (string) $r['action']);
        $out['target'] = str_or_empty($r['target_label']);
        if ($type === 'audit') {
            $out['before'] = $r['before_data'] === null ? null : json_decode((string) $r['before_data'], true);
            $out['after'] = $r['after_data'] === null ? null : json_decode((string) $r['after_data'], true);
        }
        return $out;
    }
}
