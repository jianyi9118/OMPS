<?php
namespace Omps\Tests;

/**
 * Every paged list accepts the shared filters (period, keyword in all fields or one field) and the
 * chosen page size: no SQL error for any field key the browser offers, and the size comes back.
 */
class ListFilterTest extends TestCase
{
    public function testEveryListTakesTheFilters()
    {
        $this->login($this->user('super')['username']);
        $year = date('Y');
        // endpoint => field keys offered in the browser
        $lists = array(
            '/api/v1/devices' => array('asset_no', 'name', 'model', 'serial_no', 'holder', 'dept', 'location', 'remark'),
            '/api/v1/device-faults' => array('asset_no', 'device', 'location', 'description', 'cancel_reason'),
            '/api/v1/device-repairs' => array('asset_no', 'device', 'description', 'repairer', 'result'),
            '/api/v1/device-usage' => array('asset_no', 'device', 'computer', 'ip'),
            '/api/v1/device-anomalies' => array('asset_no', 'device', 'changes', 'handler', 'note'),
            '/api/v1/device-collectors' => array('asset_no', 'device', 'computer', 'os', 'version', 'ip'),
            '/api/v1/issues' => array('title', 'body', 'sender'),
            '/api/v1/notifications' => array(),
            '/api/v1/notes' => array('title', 'body', 'reply'),
            '/api/v1/todo/history' => array(),
            '/api/v1/logs/operation' => array('user', 'target', 'ip'),
            '/api/v1/logs/audit' => array('user', 'target', 'ip'),
            '/api/v1/logs/login' => array('user', 'ip', 'agent'),
            '/api/v1/users' => array('username', 'name', 'dept'),
            '/api/v1/reports' => array(),
            '/api/v1/staff' => array('staff_no', 'name', 'dept', 'position', 'phone'),
            '/api/v1/staff/transfers' => array('name', 'from_dept', 'to_dept', 'position', 'by', 'note'),
            '/api/v1/reviews' => array('name', 'dept', 'reviewer', 'comment'),
            '/api/v1/family' => array('person', 'staff_no', 'dept', 'name', 'employer', 'position'),
            '/api/v1/me/requests' => array('reviewer', 'comment'),
            '/api/v1/perf/reviews' => array('person', 'dept', 'task', 'done', 'problems', 'next', 'reviewer', 'comment'),
        );
        foreach ($lists as $path => $fields) {
            foreach (array_merge(array(''), $fields) as $field) {
                $q = array('page' => 1, 'size' => 10, 'from' => $year . '-01-01', 'to' => $year . '-12-31', 'kw' => '测试x', 'field' => $field);
                $r = $this->request('GET', $path, null, $q);
                $this->status(200, $r, $path . ' field ' . ($field === '' ? '(all)' : $field));
            }
            $plain = $this->data($this->request('GET', $path, null, array('page' => 1, 'size' => 10)));
            if (isset($plain['size'])) {
                $this->same(10, (int) $plain['size'], $path . ' page size');
            }
        }
        $this->same(30, (int) $this->data($this->request('GET', '/api/v1/users', null, array('page' => 1)))['size'], 'default page size');
    }
}
