<?php
namespace Omps\Core;

/**
 * Minimal Word writer (no libraries): a list of blocks becomes a .docx (zip extension) or,
 * without zip, an HTML file Word opens as .doc (design §01: Word download).
 * Block: ['type' => title|meta|h1|h2|p|item|note, 'text' => string, 'tag' => optional bold lead].
 */
class Docx
{
    public static function available()
    {
        return Xlsx::available();
    }

    private static function x($s)
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s);
        return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function run($text, $bold, $size, $color = null)
    {
        $rpr = '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="SimSun"/>' . ($bold ? '<w:b/>' : '') . ($color ? '<w:color w:val="' . $color . '"/>' : '')
            . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';
        $parts = explode("\n", (string) $text);
        $out = '';
        foreach ($parts as $i => $p) {
            $out .= '<w:r>' . $rpr . ($i > 0 ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . self::x($p) . '</w:t></w:r>';
        }
        return $out;
    }

    /** @return string .docx bytes */
    public static function write(array $blocks, $title = '')
    {
        $body = '';
        foreach ($blocks as $b) {
            $t = isset($b['text']) ? (string) $b['text'] : '';
            $tag = isset($b['tag']) ? (string) $b['tag'] : '';
            switch ($b['type']) {
                case 'title':
                    $body .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="160"/></w:pPr>' . self::run($t, true, 32) . '</w:p>';
                    break;
                case 'meta':
                    $body .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="200"/></w:pPr>' . self::run($t, false, 20, '666666') . '</w:p>';
                    break;
                case 'h1':
                    $body .= '<w:p><w:pPr><w:spacing w:before="240" w:after="120"/></w:pPr>' . self::run($t, true, 26) . ($tag !== '' ? self::run('  ' . $tag, false, 20, '666666') : '') . '</w:p>';
                    break;
                case 'h2':
                    $body .= '<w:p><w:pPr><w:spacing w:before="120" w:after="60"/><w:ind w:left="240"/></w:pPr>' . self::run($t, true, 22) . '</w:p>';
                    break;
                case 'note':
                    $body .= '<w:p><w:pPr><w:ind w:left="240"/></w:pPr>' . self::run($t, false, 20, '666666') . '</w:p>';
                    break;
                case 'item':
                    $body .= '<w:p><w:pPr><w:ind w:left="480"/><w:spacing w:after="40"/></w:pPr>' . ($tag !== '' ? self::run($tag . '  ', true, 21) : '') . self::run($t, false, 21) . '</w:p>';
                    break;
                default:
                    $body .= '<w:p><w:pPr><w:spacing w:after="120"/></w:pPr>' . self::run($t, false, 21) . '</w:p>';
            }
        }
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1300" w:bottom="1440" w:left="1300" w:header="851" w:footer="992" w:gutter="0"/></w:sectPr></w:body></w:document>';
        $tmp = tempnam(sys_get_temp_dir(), 'omd');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::x($title) . '</dc:title><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $zip->addFromString('word/document.xml', $doc);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /** Fallback without zip: HTML that Word opens (.doc). */
    public static function html(array $blocks, $title = '')
    {
        $e = function ($s) {
            return nl2br(htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'));
        };
        $h = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8"><title>' . $e($title) . '</title>'
            . '<style>body{font-family:Calibri,SimSun;font-size:10.5pt}h1{text-align:center;font-size:16pt}.meta{text-align:center;color:#666}h2{font-size:13pt;margin-top:14pt}h3{font-size:11pt;margin-left:12pt}.note{margin-left:12pt;color:#666}.item{margin-left:24pt}</style></head><body>';
        foreach ($blocks as $b) {
            $t = isset($b['text']) ? $b['text'] : '';
            $tag = isset($b['tag']) ? (string) $b['tag'] : '';
            switch ($b['type']) {
                case 'title':
                    $h .= '<h1>' . $e($t) . '</h1>';
                    break;
                case 'meta':
                    $h .= '<p class="meta">' . $e($t) . '</p>';
                    break;
                case 'h1':
                    $h .= '<h2>' . $e($t) . ($tag !== '' ? ' <small style="font-weight:normal;color:#666">' . $e($tag) . '</small>' : '') . '</h2>';
                    break;
                case 'h2':
                    $h .= '<h3>' . $e($t) . '</h3>';
                    break;
                case 'note':
                    $h .= '<p class="note">' . $e($t) . '</p>';
                    break;
                case 'item':
                    $h .= '<p class="item">' . ($tag !== '' ? '<b>' . $e($tag) . '</b> ' : '') . $e($t) . '</p>';
                    break;
                default:
                    $h .= '<p>' . $e($t) . '</p>';
            }
        }
        return $h . '</body></html>';
    }
}
