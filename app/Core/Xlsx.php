<?php
namespace Omps\Core;

/**
 * Minimal .xlsx reading and writing with ZipArchive only (no libraries).
 *  - read(): first worksheet as rows of strings (shared strings, inline strings, numbers,
 *    booleans; date cells come back as Y-m-d when the cell uses a date number format).
 *  - write(): one worksheet, first row bold header, text cells.
 * CSV is also accepted by readTable() (UTF-8, or GB18030 as saved by Excel on Chinese Windows).
 */
class Xlsx
{
    public static function available()
    {
        return class_exists('ZipArchive');
    }

    /** @return array rows (each a list of strings) */
    public static function readTable($path, $ext)
    {
        $ext = strtolower((string) $ext);
        if ($ext === 'csv' || $ext === 'txt') {
            return self::readCsv($path);
        }
        if ($ext === 'xlsx') {
            return self::read($path);
        }
        throw new \InvalidArgumentException('Unsupported table file');
    }

    public static function readCsv($path)
    {
        $raw = (string) file_get_contents($path);
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'GB18030');
        }
        return self::parseCsv($raw);
    }

    /**
     * CSV parser (RFC 4180 quoting). PHP's fgetcsv() depends on the server locale and can merge
     * fields after multibyte characters, so this is done by hand on UTF-8 text.
     */
    public static function parseCsv($text)
    {
        $rows = array();
        $row = array();
        $field = '';
        $quoted = false;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($quoted) {
                if ($ch === '"') {
                    if ($i + 1 < $len && $text[$i + 1] === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $quoted = false;
                    }
                } else {
                    $field .= $ch;
                }
                continue;
            }
            if ($ch === '"' && $field === '') {
                $quoted = true;
            } elseif ($ch === ',') {
                $row[] = trim($field);
                $field = '';
            } elseif ($ch === "\n" || $ch === "\r") {
                if ($ch === "\r" && $i + 1 < $len && $text[$i + 1] === "\n") {
                    $i++;
                }
                $row[] = trim($field);
                $field = '';
                if (implode('', $row) !== '') {
                    $rows[] = $row;
                }
                $row = array();
            } else {
                $field .= $ch;
            }
        }
        if ($field !== '' || $row) {
            $row[] = trim($field);
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public static function read($path)
    {
        if (!self::available()) {
            throw new \RuntimeException('zip extension missing');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Not a valid .xlsx file');
        }
        $shared = array();
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = self::xml($ss);
            foreach ($x->si as $si) {
                $shared[] = self::siText($si);
            }
        }
        $dateStyles = self::dateStyles($zip->getFromName('xl/styles.xml'));
        $sheetPath = self::firstSheetPath($zip);
        $sheet = $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheet === false) {
            throw new \RuntimeException('Worksheet not found');
        }
        $x = self::xml($sheet);
        $rows = array();
        if (!isset($x->sheetData)) {
            return $rows;
        }
        foreach ($x->sheetData->row as $row) {
            $vals = array();
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::colIndex(preg_replace('/\d+/', '', $ref));
                $t = (string) $c['t'];
                $s = (int) $c['s'];
                if ($t === 's') {
                    $v = isset($shared[(int) $c->v]) ? $shared[(int) $c->v] : '';
                } elseif ($t === 'inlineStr') {
                    $v = self::siText($c->is);
                } elseif ($t === 'b') {
                    $v = (string) $c->v === '1' ? '1' : '0';
                } else {
                    $v = (string) $c->v;
                    if ($v !== '' && is_numeric($v) && isset($dateStyles[$s])) {
                        $v = gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
                    } elseif ($v !== '' && is_numeric($v) && preg_match('/^-?\d+\.0+$/', $v)) {
                        $v = (string) (int) $v;
                    }
                }
                $vals[$col] = trim($v);
            }
            if (!$vals) {
                continue;
            }
            $max = max(array_keys($vals));
            $line = array();
            for ($i = 0; $i <= $max; $i++) {
                $line[] = isset($vals[$i]) ? $vals[$i] : '';
            }
            $rows[] = $line;
        }
        return $rows;
    }

    private static function xml($s)
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string((string) $s, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($x === false) {
            throw new \RuntimeException('Broken xlsx content');
        }
        return $x;
    }

    private static function siText($si)
    {
        if (isset($si->t)) {
            return (string) $si->t;
        }
        $out = '';
        foreach ($si->r as $r) {
            $out .= (string) $r->t;
        }
        return $out;
    }

    private static function firstSheetPath(\ZipArchive $zip)
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $w = self::xml($wb);
            $w->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheets = $w->xpath('//m:sheets/m:sheet');
            if ($sheets) {
                $rid = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
                $r = self::xml($rels);
                foreach ($r->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = (string) $rel['Target'];
                        return strpos($target, '/') === 0 ? ltrim($target, '/') : 'xl/' . $target;
                    }
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** Style indexes whose number format is a date. */
    private static function dateStyles($stylesXml)
    {
        if ($stylesXml === false) {
            return array();
        }
        $x = self::xml($stylesXml);
        $custom = array();
        if (isset($x->numFmts)) {
            foreach ($x->numFmts->numFmt as $f) {
                $code = strtolower((string) $f['formatCode']);
                if (preg_match('/[ymd]/', preg_replace('/\[[^\]]*\]|"[^"]*"/', '', $code))) {
                    $custom[(int) $f['numFmtId']] = true;
                }
            }
        }
        $out = array();
        if (isset($x->cellXfs)) {
            $i = 0;
            foreach ($x->cellXfs->xf as $xf) {
                $id = (int) $xf['numFmtId'];
                if (($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 50 && $id <= 58) || isset($custom[$id])) {
                    $out[$i] = true;
                }
                $i++;
            }
        }
        return $out;
    }

    public static function colIndex($letters)
    {
        $n = 0;
        $letters = strtoupper((string) $letters);
        for ($i = 0, $l = strlen($letters); $i < $l; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    private static function colName($i)
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = (int) (($i - $m) / 26);
        }
        return $s;
    }

    /** Build an .xlsx file and return its bytes. All cells are written as text. */
    public static function write(array $header, array $rows, $sheetName = 'Sheet1')
    {
        if (!self::available()) {
            throw new \RuntimeException('zip extension missing');
        }
        $esc = function ($v) {
            $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v);
            return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };
        $xmlRows = '';
        $all = array_merge(array($header), $rows);
        foreach ($all as $ri => $r) {
            $xmlRows .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($r) as $ci => $v) {
                $xmlRows .= '<c r="' . self::colName($ci) . ($ri + 1) . '" t="inlineStr"' . ($ri === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
            }
            $xmlRows .= '</row>';
        }
        $cols = '<cols>';
        foreach ($header as $ci => $h) {
            $cols .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="18" customWidth="1"/>';
        }
        $cols .= '</cols>';
        $tmp = tempnam(sys_get_temp_dir(), 'omx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/><xf numFmtId="49" fontId="1" fillId="0" borderId="0" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' . $cols . '<sheetData>' . $xmlRows . '</sheetData></worksheet>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
}
