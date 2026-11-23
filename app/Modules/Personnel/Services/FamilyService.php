<?php
namespace Omps\Modules\Personnel\Services;

use Omps\Core\Crypto;
use Omps\Core\Request;
use Omps\Database\DB;
use Omps\Engines\Audit;
use Omps\Engines\Permission;

/**
 * Family records page (highly sensitive, design §06): needs family.view; phone and address in full
 * only with family.sensitive. Every look at the list is written to the audit log.
 */
class FamilyService
{
    public static function search(Request $req, array $f, $page, $size)
    {
        $full = Permission::can($req->user, 'family.sensitive');
        $q = DB::table('personnel_families fa')->join('personnel_profiles p', 'p.id', '=', 'fa.person_id')->leftJoin('departments d', 'd.id', '=', 'p.dept_id')
            ->whereNull('p.deleted_at')
            ->select(array('fa.*', 'p.name as person_name', 'p.staff_no', 'd.name as dept_name'));
        Permission::scopePersonnel($q, $req->user, 'p');
        if (isset($f['kw']) && $f['kw'] !== '') {
            $q->whereLikeAny(array('p.name', 'fa.name'), $f['kw']);
        }
        if (!empty($f['dept_id'])) {
            $q->whereIn('p.dept_id', Permission::subtree((int) $f['dept_id']));
        }
        $res = $q->orderBy('p.staff_no')->orderBy('fa.sort')->orderBy('fa.id')->paginate($page, $size);
        $rows = array();
        foreach ($res['rows'] as $r) {
            $rows[] = array(
                'id' => (int) $r['id'], 'person_id' => (int) $r['person_id'], 'person' => (string) $r['person_name'], 'staff_no' => (string) $r['staff_no'],
                'dept' => str_or_empty($r['dept_name']), 'name' => (string) $r['name'], 'relation' => (string) $r['relation'], 'gender' => str_or_empty($r['gender']),
                'birth_date' => str_or_empty($r['birth_date']), 'employer' => str_or_empty($r['employer']), 'position' => str_or_empty($r['position']),
                'phone' => $full ? str_or_empty(Crypto::decrypt($r['phone_enc'])) : str_or_empty($r['phone_mask']),
                'address' => $full ? str_or_empty(Crypto::decrypt($r['address_enc'])) : ($r['address_enc'] !== null ? '******' : ''),
            );
        }
        $res['rows'] = $rows;
        $res['full'] = $full;
        Audit::log($req->user, 'family', 'family_list_viewed', 'family', null, null, null, array('page' => $res['page'], 'kw' => isset($f['kw']) ? $f['kw'] : '', 'full' => $full), $req->ip());
        return $res;
    }
}
