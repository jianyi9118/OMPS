<?php
namespace Omps\Core;

/**
 * Small .docx writer for the built-in layouts (phase 8). The result is an ordinary Word document with
 * named paragraph styles, a header, a page-number footer and tables; it may contain {{placeholders}}
 * and is then filled by DocxFill like an uploaded template. Super administrators can download it as a
 * starting point, change it in Word and upload it again.
 *
 * Style: ['id', 'name', 'font' => east-Asian font, 'latin', 'size' => pt, 'bold', 'color', 'align',
 *         'indent' => first-line indent in characters, 'before', 'after' => pt, 'line' => exact pt]
 */
class DocxBuilder
{
    const PAGE_W = 11906;
    const PAGE_H = 16838;

    private $styles = array();
    private $body = '';
    private $header = '';
    private $footer = false;
    private $margins = array(1440, 1304, 1440, 1304);
    private $title = '';

    public static function x($s)
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s);
        return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function __construct($title = '')
    {
        $this->title = (string) $title;
    }

    /** Margins in twips: top, right, bottom, left. */
    public function margins($top, $right, $bottom, $left)
    {
        $this->margins = array((int) $top, (int) $right, (int) $bottom, (int) $left);
        return $this;
    }

    public function width()
    {
        return self::PAGE_W - $this->margins[1] - $this->margins[3];
    }

    public function style(array $s)
    {
        $this->styles[$s['id']] = $s;
        return $this;
    }

    public function header($text)
    {
        $this->header = (string) $text;
        return $this;
    }

    public function pageNumbers()
    {
        $this->footer = true;
        return $this;
    }

    private static function rpr(array $o)
    {
        $x = '';
        if (isset($o['font']) || isset($o['latin'])) {
            $latin = isset($o['latin']) ? $o['latin'] : 'Times New Roman';
            $x .= '<w:rFonts w:ascii="' . self::x($latin) . '" w:hAnsi="' . self::x($latin) . '"' . (isset($o['font']) ? ' w:eastAsia="' . self::x($o['font']) . '"' : '') . '/>';
        }
        if (!empty($o['bold'])) {
            $x .= '<w:b/><w:bCs/>';
        }
        if (isset($o['color'])) {
            $x .= '<w:color w:val="' . $o['color'] . '"/>';
        }
        if (isset($o['size'])) {
            $hp = (int) round($o['size'] * 2);
            $x .= '<w:sz w:val="' . $hp . '"/><w:szCs w:val="' . $hp . '"/>';
        }
        return $x === '' ? '' : '<w:rPr>' . $x . '</w:rPr>';
    }

    private static function ppr(array $o, $style = null)
    {
        $x = $style !== null ? '<w:pStyle w:val="' . $style . '"/>' : '';
        if (isset($o['keep'])) {
            $x .= '<w:keepNext/>';
        }
        if (isset($o['before']) || isset($o['after']) || isset($o['line'])) {
            $x .= '<w:spacing' . (isset($o['before']) ? ' w:before="' . (int) ($o['before'] * 20) . '"' : '') . (isset($o['after']) ? ' w:after="' . (int) ($o['after'] * 20) . '"' : '')
                . (isset($o['line']) ? ' w:line="' . (int) ($o['line'] * 20) . '" w:lineRule="exact"' : '') . '/>';
        }
        if (isset($o['indent'])) {
            $x .= '<w:ind w:firstLineChars="' . (int) ($o['indent'] * 100) . '"/>';
        }
        if (isset($o['align'])) {
            $x .= '<w:jc w:val="' . $o['align'] . '"/>';
        }
        return $x === '' ? '' : '<w:pPr>' . $x . '</w:pPr>';
    }

    private static function runs($text, array $o = array())
    {
        $out = '';
        foreach (explode("\n", (string) $text) as $i => $line) {
            $out .= '<w:r>' . self::rpr($o) . ($i > 0 ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . self::x($line) . '</w:t></w:r>';
        }
        return $out;
    }

    /** A paragraph; $style = a style id, $o = direct formatting (align, bold, size, color, ...). */
    public function p($text, $style = null, array $o = array())
    {
        $this->body .= self::paragraph($text, $style, $o);
        return $this;
    }

    private static function paragraph($text, $style = null, array $o = array())
    {
        $run = array_intersect_key($o, array('font' => 1, 'latin' => 1, 'bold' => 1, 'color' => 1, 'size' => 1));
        return '<w:p>' . self::ppr($o, $style) . self::runs($text, $run) . '</w:p>';
    }

    /**
     * A bordered table. $cols = widths in twips; $rows = list of ['cells' => [...], 'h' => min height twips, 'header' => repeat]
     * Cell: ['text', 'span', 'merge' => restart|continue, 'label' => shaded and centred, 'align', 'bold', 'style'].
     */
    public function table(array $cols, array $rows, $cellStyle = null)
    {
        $total = array_sum($cols);
        $x = '<w:tbl><w:tblPr><w:tblW w:w="' . $total . '" w:type="dxa"/><w:jc w:val="center"/><w:tblBorders>';
        foreach (array('top', 'left', 'bottom', 'right', 'insideH', 'insideV') as $b) {
            $x .= '<w:' . $b . ' w:val="single" w:sz="4" w:space="0" w:color="000000"/>';
        }
        $x .= '</w:tblBorders><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="85" w:type="dxa"/><w:right w:w="85" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($cols as $w) {
            $x .= '<w:gridCol w:w="' . (int) $w . '"/>';
        }
        $x .= '</w:tblGrid>';
        foreach ($rows as $row) {
            $x .= '<w:tr><w:trPr>' . (!empty($row['header']) ? '<w:tblHeader/>' : '') . '<w:cantSplit/>' . (isset($row['h']) ? '<w:trHeight w:val="' . (int) $row['h'] . '"/>' : '') . '</w:trPr>';
            $ci = 0;
            foreach ($row['cells'] as $c) {
                $span = isset($c['span']) ? (int) $c['span'] : 1;
                $w = array_sum(array_slice($cols, $ci, $span));
                $ci += $span;
                $label = !empty($c['label']);
                $x .= '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '')
                    . (isset($c['merge']) ? '<w:vMerge' . ($c['merge'] === 'restart' ? ' w:val="restart"' : '') . '/>' : '')
                    . (isset($c['fill']) ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $c['fill'] . '"/>' : ($label ? '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>' : ''))
                    . '<w:vAlign w:val="center"/></w:tcPr>';
                $o = array('align' => isset($c['align']) ? $c['align'] : ($label ? 'center' : 'left'));
                if ($label || !empty($c['bold'])) {
                    $o['bold'] = true;
                }
                $x .= self::paragraph(isset($c['text']) ? $c['text'] : '', isset($c['style']) ? $c['style'] : $cellStyle, $o) . '</w:tc>';
            }
            $x .= '</w:tr>';
        }
        $this->body .= $x . '</w:tbl>';
        return $this;
    }

    private function stylesXml()
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="SimSun" w:cs="Times New Roman"/><w:sz w:val="21"/><w:szCs w:val="21"/><w:lang w:val="en-US" w:eastAsia="zh-CN"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:widowControl w:val="0"/><w:jc w:val="both"/></w:pPr></w:pPrDefault></w:docDefaults>';
        $normal = isset($this->styles['Normal']) ? $this->styles['Normal'] : array('id' => 'Normal', 'name' => 'Normal');
        $all = array('Normal' => $normal) + $this->styles;
        foreach ($all as $id => $s) {
            $isNormal = $id === 'Normal';
            $x .= '<w:style w:type="paragraph"' . ($isNormal ? ' w:default="1"' : ' w:customStyle="1"') . ' w:styleId="' . $id . '"><w:name w:val="' . self::x($isNormal ? 'Normal' : $s['name']) . '"/>'
                . ($isNormal ? '' : '<w:basedOn w:val="Normal"/><w:qFormat/>') . self::ppr($s) . self::rpr($s) . '</w:style>';
        }
        $x .= '<w:style w:type="paragraph" w:styleId="Header"><w:name w:val="header"/><w:basedOn w:val="Normal"/><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="4" w:space="1" w:color="808080"/></w:pBdr><w:jc w:val="right"/></w:pPr><w:rPr><w:color w:val="595959"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Footer"><w:name w:val="footer"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/></w:pPr><w:rPr><w:rFonts w:ascii="SimSun" w:hAnsi="SimSun" w:eastAsia="SimSun"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:style>'
            . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>';
        return $x . '</w:styles>';
    }

    /** @return string .docx bytes */
    public function bytes()
    {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $refs = ($this->header !== '' ? '<w:headerReference w:type="default" r:id="rIdH"/>' : '') . ($this->footer ? '<w:footerReference w:type="default" r:id="rIdF"/>' : '');
        list($t, $r, $b, $l) = $this->margins;
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $this->body
            . '<w:sectPr>' . $refs . '<w:pgSz w:w="' . self::PAGE_W . '" w:h="' . self::PAGE_H . '"/><w:pgMar w:top="' . $t . '" w:right="' . $r . '" w:bottom="' . $b . '" w:left="' . $l . '" w:header="851" w:footer="850" w:gutter="0"/></w:sectPr></w:body></w:document>';
        $rels = '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rIdT" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>';
        $types = '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $files = array();
        if ($this->header !== '') {
            $rels .= '<Relationship Id="rIdH" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>';
            $types .= '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>';
            $files['word/header1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . $ns . '>' . self::paragraph($this->header, 'Header') . '</w:hdr>';
        }
        if ($this->footer) {
            $rels .= '<Relationship Id="rIdF" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
            $types .= '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>';
            $files['word/footer1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $ns . '><w:p><w:pPr><w:pStyle w:val="Footer"/></w:pPr>'
                . '<w:r><w:t xml:space="preserve">— </w:t></w:r><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>'
                . '<w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r><w:r><w:t xml:space="preserve"> —</w:t></w:r></w:p></w:ftr>';
        }
        $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' . $types . '</Types>';
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>';
        $files['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::x($this->title) . '</dc:title><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>';
        $files['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
        $files['word/styles.xml'] = $this->stylesXml();
        $files['word/settings.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:defaultTabStop w:val="420"/><w:characterSpacingControl w:val="compressPunctuation"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>';
        $files['word/document.xml'] = $doc;
        $tmp = tempnam(sys_get_temp_dir(), 'omb');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
}
