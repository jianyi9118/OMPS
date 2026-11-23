<?php
namespace Omps\Tests;

use Omps\Core\Crypto;
use Omps\Database\DB;
use Omps\Engines\Documents;

/**
 * Phase 8: Word templates. The fill engine (placeholders split over runs, any language, lists,
 * body blocks, pictures, unknown names), template management by the super administrator, and the
 * registration form, which holds only what the viewer may see.
 */
class DocumentTest extends TestCase
{
    const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

    /** A minimal .docx with the given body XML. */
    private static function docx($body)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tdx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document ' . self::W . '><w:body>' . $body . '<w:sectPr/></w:body></w:document>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    private static function part($bytes, $name)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tdr');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $x = $zip->getFromName($name);
        $zip->close();
        @unlink($tmp);
        return $x;
    }

    /** Plain text of a document: paragraphs on lines, line breaks as "|". */
    private static function text($xml)
    {
        $xml = preg_replace('#<w:br/>#', '|', $xml);
        $xml = preg_replace('#</w:p>#', "\n", $xml);
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function p($text, $bold = false)
    {
        return '<w:p><w:r>' . ($bold ? '<w:rPr><w:b/></w:rPr>' : '') . '<w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
    }

    private static function residentId()
    {
        $id = '110105' . '1980' . sprintf('%02d%02d', mt_rand(1, 12), mt_rand(1, 28)) . sprintf('%03d', mt_rand(0, 999));
        $w = array(7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2);
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += (int) $id[$i] * $w[$i];
        }
        $check = '10X98765432';
        return $id . $check[$sum % 11];
    }

    public function testFillEngine()
    {
        // {{标题}} typed in three runs with different formatting; {{title}} in English; an unknown name
        $body = '<w:p><w:r><w:t>{{</w:t></w:r><w:proofErr w:type="spellStart"/><w:r><w:rPr><w:b/></w:rPr><w:t>标</w:t></w:r><w:r><w:t>题}} / {{ title }}</w:t></w:r></w:p>'
            . self::p('{{补充说明}}') . self::p('{{无此项}}') . self::p('{{正文}}', true)
            . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>{{业绩.序号}}</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>{{业绩.填写人}}：{{业绩.业绩内容}}</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>{{results.person}}</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            . self::p('{{单位名称}}');
        $model = array('fields' => array('title' => 'A & B <C>', 'outro' => '', 'unit_name' => '单位' . "\n" . '第二行'),
            'blocks' => array('body' => array(array('type' => 'h1', 'text' => '一、甲', 'tag' => '完成 50%'), array('type' => 'item', 'tag' => '10 月 张三：', 'text' => '做了事'))),
            'lists' => array('results' => array(array('person' => '张三', 'done' => '甲'), array('person' => '李四', 'done' => '乙'))));
        $out = Documents::fillBytes(self::docx($body), 'report', $model);
        $xml = self::part($out, 'word/document.xml');
        $this->ok(@simplexml_load_string($xml) !== false, 'well-formed');
        $txt = self::text($xml);
        $this->contains('A & B <C> / A & B <C>', $txt, 'split placeholder joined, English name, escaped');
        $this->contains('{{无此项}}', $txt, 'unknown placeholder kept');
        $this->notContains('补充说明', $txt);
        $this->contains("一、甲  完成 50%\n10 月 张三：  做了事\n", $txt, 'body block becomes paragraphs');
        $this->contains("1\n张三：甲\n2\n李四：乙\n", $txt, 'table row repeated per result with numbers');
        $this->contains("张三\n李四\n", $txt);
        $this->contains('单位|第二行', $txt, 'line break');
        $this->same(4, substr_count($xml, '<w:tr>') + substr_count($xml, '<w:tr '), 'rows: 2 + 2');
        // an empty list keeps one blank table row; paragraphs that only held an empty list disappear
        $out = Documents::fillBytes(self::docx('<w:tbl><w:tr><w:tc><w:p><w:r><w:t>{{业绩.任务}}</w:t></w:r></w:p></w:tc></w:tr></w:tbl>' . self::p('x {{业绩.任务}}') . self::p('end')), 'report', array('lists' => array('results' => array())));
        $xml = self::part($out, 'word/document.xml');
        $this->same(1, substr_count($xml, '<w:tr'), 'blank row kept');
        $this->notContains('x ', self::text($xml));
        // a picture
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4//8/AAX+Av4N70a4AAAAAElFTkSuQmCC');
        $out = Documents::fillBytes(self::docx(self::p('{{照片}}') . self::p('{{photo}}')), 'person', array('images' => array('photo' => array('bytes' => $png, 'ext' => 'png', 'w' => 25, 'h' => 35))));
        $this->contains('<w:drawing', self::part($out, 'word/document.xml'));
        $this->same($png, self::part($out, 'word/media/omps_img1.png'), 'picture stored');
        $this->contains('rIdOmps2', self::part($out, 'word/_rels/document.xml.rels'), 'relationships added');
        $this->contains('Extension="png"', self::part($out, '[Content_Types].xml'));
        // no picture: nothing
        $out = Documents::fillBytes(self::docx(self::p('{{照片}}')), 'person', array('images' => array('photo' => null)));
        $this->notContains('照片', self::part($out, 'word/document.xml'));
    }

    public function testTemplates()
    {
        $super = $this->user('super');
        $unit = $this->user('unit_head', $this->person('模板单位' . mt_rand(100, 999), null));
        $this->login($unit['username']);
        $this->status(403, $this->request('GET', '/api/v1/doc-templates'), 'super admin only');
        $this->status(200, $this->request('GET', '/api/v1/doc-templates/choices', null, array('type' => 'report')), 'choices for everyone');
        $this->login($super['username']);
        $idx = $this->data($this->request('GET', '/api/v1/doc-templates'));
        $this->same(array('report', 'person'), array_map(function ($t) { return $t['type']; }, $idx['types']));
        $this->contains('标题', implode(' ', $idx['types'][0]['help']['fields']), 'help in the current language');
        // the built-in layouts as samples and trial fills
        $sample = $this->request('GET', '/api/v1/doc-templates/sample', null, array('type' => 'report'));
        $this->status(200, $sample);
        $this->contains('{{标题}}', self::text(self::part($sample->body, 'word/document.xml')));
        $trial = $this->request('GET', '/api/v1/doc-templates/test', null, array('type' => 'person', 'id' => 0));
        $this->contains('【姓名】', self::text(self::part($trial->body, 'word/document.xml')), 'trial shows the names');
        // uploads: Word files with known placeholders only
        $dir = sys_get_temp_dir();
        $txt = $dir . '/omps-t.txt';
        file_put_contents($txt, 'x');
        $this->status(422, $this->upload('/api/v1/doc-templates', array(array('path' => $txt, 'name' => 'a.docx')), array('type' => 'report')), 'not a docx');
        $none = $dir . '/omps-none.docx';
        file_put_contents($none, self::docx(self::p('{{abc}} hello')));
        $this->status(422, $this->upload('/api/v1/doc-templates', array(array('path' => $none, 'name' => 'none.docx')), array('type' => 'report')), 'no known placeholder');
        $tplFile = $dir . '/omps-tpl.docx';
        file_put_contents($tplFile, self::docx(self::p('报告：{{标题}}') . self::p('{{正文}}') . self::p('{{作者}}')));
        $r = $this->upload('/api/v1/doc-templates', array(array('path' => $tplFile, 'name' => '单位报告.docx')), array('type' => 'report'));
        $this->status(200, $r, 'upload');
        $up = $this->data($r);
        $this->same(array('作者'), $up['unknown'], 'unknown names reported');
        $choices = $this->data($this->request('GET', '/api/v1/doc-templates/choices', null, array('type' => 'report')))['choices'];
        $this->same(array($up['id'], 0), array_map(function ($c) { return $c['id']; }, $choices), 'the new upload is used, built-in second');
        $this->ok($choices[0]['default']);
        // a report downloads with it
        $rep = $this->data($this->request('POST', '/api/v1/reports/manual', array('title' => '十月总结', 'start' => '2026-10-01', 'end' => '2026-10-31', 'body' => "第一段\n\n第二段")));
        $w = $this->request('GET', '/api/v1/reports/' . $rep['id'] . '/download', null, array('format' => 'docx'));
        $this->status(200, $w);
        $txt2 = self::text(self::part($w->body, 'word/document.xml'));
        $this->contains("报告：十月总结\n第一段\n第二段\n{{作者}}", $txt2);
        $this->same($up['id'], (int) DB::value('SELECT template_id FROM generated_documents WHERE biz_type = ? AND biz_id = ? ORDER BY id DESC LIMIT 1', array('report', $rep['id'])), 'recorded');
        $b = $this->request('GET', '/api/v1/reports/' . $rep['id'] . '/download', null, array('format' => 'docx', 'tpl' => 0));
        $this->contains('十月总结', self::text(self::part($b->body, 'word/document.xml')), 'built-in on request');
        $this->notContains('报告：', self::text(self::part($b->body, 'word/document.xml')));
        $this->status(404, $this->request('GET', '/api/v1/reports/' . $rep['id'] . '/download', null, array('format' => 'docx', 'tpl' => $up['id'] + 1000)));
        // switch back, try, delete
        $this->status(200, $this->request('POST', '/api/v1/doc-templates/default', array('type' => 'report', 'id' => 0)));
        $this->same(0, (int) DB::value('SELECT COUNT(*) FROM doc_templates WHERE is_default = 1 AND doc_type = ?', array('report')));
        $this->contains('【标题】', self::text(self::part($this->request('GET', '/api/v1/doc-templates/test', null, array('type' => 'report', 'id' => $up['id']))->body, 'word/document.xml')));
        $orig = $this->request('GET', '/api/v1/doc-templates/' . $up['id'] . '/file');
        $this->status(200, $orig);
        $this->same('PK', (string) file_get_contents($orig->file, false, null, 0, 2), 'original file');
        $rel = (int) DB::value('SELECT relation_id FROM doc_templates WHERE id = ?', array($up['id']));
        $fid = (int) DB::value('SELECT file_id FROM file_relations WHERE id = ?', array($rel));
        $this->status(200, $this->request('DELETE', '/api/v1/doc-templates/' . $up['id']));
        $this->same('trash', (string) DB::value('SELECT status FROM files WHERE id = ?', array($fid)), 'file to the recycle bin');
        $this->same(1, count($this->data($this->request('GET', '/api/v1/doc-templates/choices', null, array('type' => 'report')))['choices']));
        $this->ok($this->auditCount('doc_template_added') >= 1 && $this->auditCount('doc_template_deleted') >= 1);
        foreach (array($txt, $none, $tplFile) as $f) {
            @unlink($f);
        }
    }

    public function testRegistrationForm()
    {
        $s = (string) mt_rand(1000, 9999);
        $dept = $this->dept('登记部' . $s);
        $other = $this->dept('登记乙部' . $s);
        $lead = $this->person('登记部长' . $s, $dept);
        $p = $this->person('登记成员' . $s, $dept);
        $q = $this->person('乙部成员' . $s, $other);
        $id = self::residentId();
        DB::table('personnel_profiles')->where('id', $p)->update(array('id_type' => 'resident_id', 'id_number_enc' => Crypto::encrypt($id), 'id_number_hash' => Crypto::blindIndex($id),
            'id_number_mask' => Crypto::mask($id), 'birth_date' => '1990-06-01', 'position' => '工程师'));
        DB::table('personnel_education')->insert(array('person_id' => $p, 'school' => '某某大学', 'start_ym' => '2008-09', 'end_ym' => '2012-06', 'degree_level' => 'bachelor', 'sort' => 1, 'created_at' => now()));
        DB::table('personnel_families')->insert(array('person_id' => $p, 'name' => '家属' . $s, 'relation' => 'spouse', 'sort' => 1, 'created_at' => now()));
        $uLead = $this->user('dept_head', $lead);
        $uP = $this->user('member', $p);
        $super = $this->user('super');

        // own form
        $this->login($uP['username']);
        $mine = $this->request('GET', '/api/v1/me/profile/document');
        $this->status(200, $mine);
        $t = self::text(self::part($mine->body, 'word/document.xml'));
        $this->contains('登记成员' . $s, $t);
        $this->contains($id, $t, 'own record: full ID number');
        $this->contains('家属' . $s, $t, 'own family');
        $this->contains('2008-09 — 2012-06', $t);
        $this->status(404, $this->request('GET', '/api/v1/personnel/' . $q . '/document'), 'members: own record only');

        // department head: masked ID, no family, audited
        $this->login($uLead['username']);
        $before = $this->auditCount('person_doc_generated');
        $r = $this->request('GET', '/api/v1/personnel/' . $p . '/document');
        $this->status(200, $r);
        $t = self::text(self::part($r->body, 'word/document.xml'));
        $this->notContains($id, $t, 'masked ID');
        $this->contains(Crypto::mask($id), $t);
        $this->notContains('家属' . $s, $t, 'no family without permission');
        $this->notContains(__('staff.group.family'), $t, 'no family section in the built-in layout');
        $this->same($before + 1, $this->auditCount('person_doc_generated'), 'audited');
        $this->status(404, $this->request('GET', '/api/v1/personnel/' . $q . '/document'), 'other department');

        // super admin: full ID and family
        $this->login($super['username']);
        $t = self::text(self::part($this->request('GET', '/api/v1/personnel/' . $p . '/document')->body, 'word/document.xml'));
        $this->contains($id, $t);
        $this->contains('家属' . $s, $t);
        $this->ok((int) DB::value("SELECT COUNT(*) FROM generated_documents WHERE doc_type = 'person' AND biz_id = ?", array($p)) === 3, 'every form recorded');
        // family lists of other tests start empty
        DB::table('personnel_families')->where('person_id', $p)->delete();
        DB::table('personnel_education')->where('person_id', $p)->delete();
    }
}
