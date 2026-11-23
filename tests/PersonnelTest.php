<?php
namespace Omps\Tests;

use Omps\Core\Crypto;
use Omps\Core\Xlsx;
use Omps\Database\DB;

/**
 * Phase 2: full staff records, encrypted ID numbers, self-service changes with review,
 * family records, attachments, import and export.
 */
class PersonnelTest extends TestCase
{
    /** @var array */
    private $f;
    const ID1 = '11010519491231002X';
    const ID2 = '440524188001010014';
    /** @var string */
    private $id1;
    /** @var string */
    private $id2;
    /** @var string */
    private $sfx;

    /** A valid resident ID number that no other test uses. */
    private static function validId()
    {
        $b = '4401' . sprintf('%02d', mt_rand(1, 99)) . sprintf('%04d', mt_rand(1950, 2005)) . sprintf('%02d', mt_rand(1, 12)) . sprintf('%02d', mt_rand(1, 28)) . sprintf('%03d', mt_rand(0, 999));
        $w = array(7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2);
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += (int) $b[$i] * $w[$i];
        }
        $c = '10X98765432';
        return $b . $c[$sum % 11];
    }

    public function setUp()
    {
        parent::setUp();
        $this->sfx = substr(md5(uniqid('', true)), 0, 4);
        $this->id1 = self::validId();
        $this->id2 = self::validId();
        $tech = $this->dept('技术部' . $this->sfx);
        $soft = $this->dept('软件组', $tech);
        $hr = $this->dept('综合部' . $this->sfx);
        $lead = $this->person('李建国', $tech);
        $wang = $this->person('王晓', $soft);
        $zhou = $this->person('周敏', $hr);
        DB::table('departments')->where('id', $tech)->update(array('leader_person_id' => $lead));
        $this->f = array('tech' => $tech, 'soft' => $soft, 'hr' => $hr, 'lead' => $lead, 'wang' => $wang, 'zhou' => $zhou,
            'uLead' => $this->user('dept_head', $lead), 'uWang' => $this->user('member', $wang), 'uZhou' => $this->user('member', $zhou), 'uUnit' => $this->user('unit_head'));
    }

    public function testCrypto()
    {
        $c = Crypto::encrypt(self::ID1);
        $this->ok(strpos($c, self::ID1) === false && strncmp($c, 'v1:', 3) === 0, 'cipher text');
        $this->same(self::ID1, Crypto::decrypt($c));
        $this->ok(Crypto::encrypt(self::ID1) !== $c, 'random IV');
        $this->same(Crypto::blindIndex(self::ID1), Crypto::blindIndex(strtolower(self::ID1)), 'index ignores case');
        $this->same('1101**********002X', Crypto::mask(self::ID1));
    }

    public function testAdminEditsFullRecordWithEncryptedId()
    {
        $this->login($this->f['uUnit']['username']);
        $p = $this->data($this->request('GET', '/api/v1/personnel/' . $this->f['wang']));
        $body = array('version' => $p['profile']['version'], 'profile' => array('name' => '王晓', 'gender' => 'female', 'id_type' => 'resident_id', 'id_number' => $this->id1,
            'birth_date' => '1949-12-31', 'ethnicity' => '汉族', 'email' => 'wx@example.cn'),
            'education' => array(array('start_ym' => '2016-09', 'end_ym' => '2020-06', 'school' => '示例大学', 'degree_level' => 'bachelor', 'degree' => 'bachelor', 'graduated' => true)),
            'work' => array(array('employer' => '示例公司', 'start_ym' => '2020-07')));
        $this->status(200, $this->request('PUT', '/api/v1/personnel/' . $this->f['wang'], $body));
        $row = DB::selectOne('SELECT * FROM personnel_profiles WHERE id = ?', array($this->f['wang']));
        $this->ok(strpos((string) $row['id_number_enc'], $this->id1) === false, 'not stored in plain text');
        $this->same(Crypto::mask($this->id1), (string) $row['id_number_mask']);
        $p = $this->data($this->request('GET', '/api/v1/personnel/' . $this->f['wang']));
        $this->same(1, count($p['education']));
        $this->same('示例大学', $p['education'][0]['school']);
        $this->ok(!isset($p['profile']['id_number']), 'full number never in the record response');
        $list = $this->data($this->request('GET', '/api/v1/staff', null, array('dept_id' => $this->f['soft'])));
        $this->same(Crypto::mask($this->id1), $list['rows'][0]['id_mask']);

        // update one education row, delete the work row
        $eduId = $p['education'][0]['id'];
        $body = array('education' => array(array('id' => $eduId, 'school' => '示例大学', 'major' => '计算机', 'degree_level' => 'bachelor')), 'work' => array());
        $this->status(200, $this->request('PUT', '/api/v1/personnel/' . $this->f['wang'], $body));
        $this->same('计算机', (string) DB::value('SELECT major FROM personnel_education WHERE id = ?', array($eduId)));
        $this->same(0, (int) DB::value('SELECT COUNT(*) FROM personnel_work_experience WHERE person_id = ?', array($this->f['wang'])));

        // duplicates are refused; any letters, digits or other characters are accepted, up to 30
        $r = $this->request('PUT', '/api/v1/personnel/' . $this->f['zhou'], array('profile' => array('id_number' => $this->id1)));
        $this->status(422, $r, 'same ID on another person');
        $free = 'ab-证件' . $this->sfx . '7';
        $r = $this->request('PUT', '/api/v1/personnel/' . $this->f['zhou'], array('profile' => array('id_type' => 'resident_id', 'id_number' => $free)));
        $this->status(200, $r, 'free-form number accepted even as a resident ID');
        $this->same(Crypto::mask(strtoupper($free)), (string) DB::value('SELECT id_number_mask FROM personnel_profiles WHERE id = ?', array($this->f['zhou'])));
        $r = $this->request('PUT', '/api/v1/personnel/' . $this->f['zhou'], array('profile' => array('id_number' => str_repeat('9', 31))));
        $this->status(422, $r, 'longer than 30');
        $hist = $this->data($this->request('GET', '/api/v1/personnel/' . $this->f['wang'] . '/history'));
        $this->ok(count($hist) >= 3, 'history recorded');
        $this->notContains($this->id1, json_encode($hist), 'history never shows the full number');
    }

    public function testRevealIdNeedsPermissionAndIsAudited()
    {
        DB::table('personnel_profiles')->where('id', $this->f['wang'])->update(\Omps\Modules\Personnel\Services\PersonnelService::idParts('resident_id', $this->id1, $this->f['wang']));
        $this->login($this->f['uLead']['username']);
        $before = $this->auditCount('access_denied');
        $this->status(403, $this->request('POST', '/api/v1/personnel/' . $this->f['wang'] . '/reveal-id', array()), 'dept head lacks the sensitive permission');
        $this->same($before + 1, $this->auditCount('access_denied'));
        $this->login($this->f['uUnit']['username']);
        $r = $this->request('POST', '/api/v1/personnel/' . $this->f['wang'] . '/reveal-id', array());
        $this->same($this->id1, $this->data($r)['id_number']);
        $this->ok($this->auditCount('id_revealed') >= 1, 'reveal audited');
        $this->login($this->f['uWang']['username']);
        $this->same($this->id1, $this->data($this->request('POST', '/api/v1/personnel/' . $this->f['wang'] . '/reveal-id', array()))['id_number'], 'own number');
    }

    public function testSelfServiceAndReview()
    {
        $this->login($this->f['uWang']['username']);
        $r = $this->request('PUT', '/api/v1/me/profile', array('profile' => array('name' => '王小')));
        $this->status(422, $r, 'name cannot be changed by oneself');
        $r = $this->request('PUT', '/api/v1/me/profile', array(
            'profile' => array('phone' => '13900001111', 'email' => 'me@example.cn'),
            'education' => array(array('school' => '示例大学', 'degree_level' => 'bachelor')),
            'family' => array(array('name' => '王建华', 'relation' => 'father', 'phone' => '13700002222', 'address' => '示例市幸福路 1 号')),
        ));
        $this->status(200, $r);
        $this->same(array('applied' => 2, 'submitted' => 2), $this->data($r));
        $row = DB::selectOne('SELECT phone, email FROM personnel_profiles WHERE id = ?', array($this->f['wang']));
        $this->same('me@example.cn', (string) $row['email'], 'email applies at once');
        $this->ok((string) $row['phone'] !== '13900001111', 'phone waits for review');
        $fam = DB::selectOne('SELECT * FROM personnel_families WHERE person_id = ?', array($this->f['wang']));
        $this->ok($fam && strpos((string) $fam['phone_enc'], '13700002222') === false && strpos((string) $fam['address_enc'], '幸福路') === false, 'family encrypted');
        $mine = $this->data($this->request('GET', '/api/v1/me/requests'));
        $this->same(2, $mine['total']);

        // the department head reviews: approve the phone, return the education entry
        $this->login($this->f['uLead']['username']);
        $list = $this->data($this->request('GET', '/api/v1/reviews'));
        $this->same(2, $list['total']);
        $phoneReq = null;
        $eduReq = null;
        foreach ($list['rows'] as $x) {
            if ($x['entity'] === 'profile') {
                $phoneReq = $x['id'];
            } else {
                $eduReq = $x['id'];
            }
        }
        $this->status(422, $this->request('POST', '/api/v1/reviews/' . $eduReq . '/return', array('comment' => '')), 'reason required');
        $this->status(200, $this->request('POST', '/api/v1/reviews/' . $eduReq . '/return', array('comment' => '请上传毕业证')));
        $a = $this->data($this->request('POST', '/api/v1/reviews/approve', array('ids' => array($phoneReq))));
        $this->same(1, $a['approved']);
        $this->same('13900001111', (string) DB::value('SELECT phone FROM personnel_profiles WHERE id = ?', array($this->f['wang'])));
        $this->same(0, (int) DB::value('SELECT COUNT(*) FROM personnel_education WHERE person_id = ?', array($this->f['wang'])), 'returned change not applied');

        // a person outside the department head's scope
        $this->login($this->f['uZhou']['username']);
        $this->request('PUT', '/api/v1/me/profile', array('profile' => array('phone' => '13600003333')));
        $zid = (int) DB::value('SELECT id FROM profile_change_requests WHERE person_id = ? ORDER BY id DESC LIMIT 1', array($this->f['zhou']));
        $this->login($this->f['uLead']['username']);
        $a = $this->data($this->request('POST', '/api/v1/reviews/approve', array('ids' => array($zid))));
        $this->same(0, $a['approved'], 'cannot approve outside scope');
        $this->same(1, count($a['failed']));
        $this->status(404, $this->request('POST', '/api/v1/reviews/' . $zid . '/return', array('comment' => 'x')), 'cannot return outside scope');
        $this->same('pending', (string) DB::value('SELECT status FROM profile_change_requests WHERE id = ?', array($zid)));

        // the department head's own change goes to the unit head, not to himself
        $this->request('PUT', '/api/v1/me/profile', array('profile' => array('phone' => '13500004444')));
        $own = $this->data($this->request('GET', '/api/v1/reviews'));
        foreach ($own['rows'] as $x) {
            $this->ok($x['person_id'] !== $this->f['lead'], 'no self review');
        }
        $this->login($this->f['uUnit']['username']);
        $all = $this->data($this->request('GET', '/api/v1/reviews', null, array('size' => 50)));
        $leadIds = array_filter($all['rows'], function ($x) { return $x['person'] === '李建国'; });
        $this->ok(count($leadIds) === 1, 'unit head reviews the department head');

        // withdraw
        $this->login($this->f['uZhou']['username']);
        $this->status(200, $this->request('POST', '/api/v1/me/requests/' . $zid . '/withdraw', array()));
        $this->status(409, $this->request('POST', '/api/v1/me/requests/' . $zid . '/withdraw', array()));
    }

    public function testFamilyIsProtected()
    {
        $this->login($this->f['uWang']['username']);
        $this->request('PUT', '/api/v1/me/profile', array('family' => array(array('name' => '王建华', 'relation' => 'father', 'phone' => '13700002222', 'address' => '幸福路 1 号'))));
        $this->login($this->f['uLead']['username']);
        $this->status(403, $this->request('GET', '/api/v1/family'), 'dept head has no family permission by default');
        $p = $this->data($this->request('GET', '/api/v1/personnel/' . $this->f['wang']));
        $this->ok(!isset($p['family']), 'family hidden in the record');
        $this->login($this->f['uUnit']['username']);
        $before = $this->auditCount('family_list_viewed');
        $list = $this->data($this->request('GET', '/api/v1/family'));
        $this->same('13700002222', $list['rows'][0]['phone'], 'full with the sensitive permission');
        $this->same($before + 1, $this->auditCount('family_list_viewed'), 'each view audited');
        // without family.sensitive: masked
        $unit = (int) DB::value("SELECT id FROM roles WHERE code = 'unit_head'");
        $sens = (int) DB::value("SELECT id FROM permissions WHERE code = 'family.sensitive'");
        DB::table('role_permissions')->where('role_id', $unit)->where('permission_id', $sens)->delete();
        \Omps\Engines\Permission::clearCache();
        $list = $this->data($this->request('GET', '/api/v1/family'));
        $this->same('137****2222', $list['rows'][0]['phone']);
        $this->same('******', $list['rows'][0]['address']);
        DB::table('role_permissions')->insert(array('role_id' => $unit, 'permission_id' => $sens));
    }

    public function testAttachments()
    {
        $png = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $fake = tempnam(sys_get_temp_dir(), 'jpg');
        file_put_contents($fake, '<?php echo 1;');
        $this->login($this->f['uWang']['username']);
        $r = $this->upload('/api/v1/me/profile/files', array(array('path' => $png, 'name' => '毕业证.png')), array('group' => 'education'));
        $this->status(200, $r);
        $this->same(false, $this->data($r)['files'][0]['duplicate']);
        $r = $this->upload('/api/v1/me/profile/files', array(array('path' => $png, 'name' => '毕业证(副本).png')), array('group' => 'other'));
        $this->same(true, $this->data($r)['files'][0]['duplicate'], 'same content stored once');
        $this->same(1, (int) DB::value('SELECT COUNT(*) FROM files WHERE uploaded_by = ?', array($this->f['uWang']['id'])));
        $this->status(422, $this->upload('/api/v1/me/profile/files', array(array('path' => $fake, 'name' => 'photo.jpg')), array('group' => 'other')), 'fake image refused');
        $path = (string) DB::value('SELECT rel_path FROM files WHERE uploaded_by = ?', array($this->f['uWang']['id']));
        $this->contains('人员档案/技术部' . $this->sfx . '/软件组/王晓/学历证明/毕业证', $path, 'folders mirror the platform');
        $this->ok(is_file(\Omps\Engines\Files::root() . '/' . $path), 'stored on disk');

        $rel = (int) DB::value("SELECT id FROM file_relations WHERE biz_type = 'personnel' AND biz_id = ? AND biz_group = 'education'", array($this->f['wang']));
        $this->login($this->f['uLead']['username']);
        $d = $this->request('GET', '/api/v1/files/' . $rel);
        $this->status(200, $d, 'department head downloads');
        $this->ok($d->file !== null && strpos($d->headers['Content-Disposition'], 'attachment') === 0, 'download');
        $this->status(403, $this->request('DELETE', '/api/v1/files/' . $rel), 'only the uploader deletes');
        $this->login($this->f['uZhou']['username']);
        $this->status(404, $this->request('GET', '/api/v1/files/' . $rel), 'outside scope');
        $this->login($this->f['uWang']['username']);
        $this->status(200, $this->request('DELETE', '/api/v1/files/' . $rel));
        $this->same('normal', (string) DB::value('SELECT status FROM files WHERE uploaded_by = ?', array($this->f['uWang']['id'])), 'still used by the other entry');
    }

    public function testImportAndExport()
    {
        $this->login($this->f['uUnit']['username']);
        $this->status(200, $this->request('GET', '/api/v1/staff-import/template'));
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, Xlsx::write(array('工号', '姓名*', '性别*', '部门*', '岗位', '证件号码', '入职日期'), array(
            array('', '赵新', '男', '技术部' . $this->sfx . '/软件组', '工程师', $this->id2, '2024/3/1'),
            array('', '钱多', '女', '综合部' . $this->sfx, '', 'e护照' . $this->sfx, '2024年5月6日'),
            array('', '孙错', '未知', '不存在的部门', '', str_repeat('1', 31), ''),
        )));
        $r = $this->upload('/api/v1/staff-import/check', array(array('path' => $tmp, 'name' => 'staff.xlsx')));
        $this->status(200, $r);
        $res = $this->data($r);
        $this->same(2, $res['ok'], 'a free-form ID number is accepted');
        $this->same(1, $res['bad']);
        $this->same(3, count($res['rows'][2]['errors']), 'gender, department and ID longer than 30');
        $run = $this->data($this->request('POST', '/api/v1/staff-import/run', array('token' => $res['token'])));
        $this->same(array('created' => 2, 'skipped' => 1), $run);
        $this->same('2024-05-06', (string) DB::value("SELECT hire_date FROM personnel_profiles WHERE name = '钱多' ORDER BY id DESC LIMIT 1"));
        $this->same(Crypto::mask($this->id2), (string) DB::value("SELECT id_number_mask FROM personnel_profiles WHERE name = '赵新' ORDER BY id DESC LIMIT 1"));
        $this->status(400, $this->request('POST', '/api/v1/staff-import/run', array('token' => $res['token'])), 'token used once');

        // CSV saved by Excel on Chinese Windows (GB18030)
        $csv = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($csv, mb_convert_encoding("姓名,性别,部门\n李四,女,综合部" . $this->sfx . "\n", 'GB18030', 'UTF-8'));
        $res = $this->data($this->upload('/api/v1/staff-import/check', array(array('path' => $csv, 'name' => 'a.csv'))));
        $this->same(1, $res['ok']);

        $x = $this->request('GET', '/api/v1/staff-export');
        $this->status(200, $x);
        $f = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($f, $x->body);
        $rows = Xlsx::read($f);
        $this->same('工号', $rows[0][0]);
        $flat = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $this->contains(Crypto::mask($this->id2), $flat);
        $this->notContains($this->id2, $flat, 'export never contains full numbers');
        $this->login($this->f['uWang']['username']);
        $this->status(403, $this->request('GET', '/api/v1/staff-export'), 'member cannot export');
        $this->status(403, $this->request('POST', '/api/v1/staff-import/run', array('token' => $res['token'])), 'member cannot import');
    }
}
