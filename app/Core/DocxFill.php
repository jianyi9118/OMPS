<?php
namespace Omps\Core;

/**
 * Fills a Word template (.docx) that contains placeholders written as {{name}} (design §7.7, phase 8).
 * No libraries: the package is opened with ZipArchive and the parts are edited with DOMDocument.
 *
 *  - Word often splits typed text into several runs; each paragraph is first normalised so that every
 *    placeholder sits inside one text node (the formatting of its first character is kept).
 *  - A list placeholder {{list.column}} repeats its container once per row: the table row it is in, or
 *    else its paragraph. With no rows a table row is kept once with empty cells, a paragraph is removed.
 *  - A block placeholder alone in a paragraph becomes several paragraphs (the report body). When the
 *    template defines the styles OmpsH1, OmpsH2, OmpsNote, OmpsItem, OmpsBody they are used, otherwise
 *    the placeholder paragraph's own formatting (headings in bold).
 *  - An image placeholder becomes a picture (fitted into 2.5 cm x 3.5 cm), or nothing without a picture.
 *  - A paragraph that held only placeholders and ends up empty is removed (not the last one of a cell).
 *  - Unknown placeholders are left as they are, so the template author sees them.
 *
 * $resolve(string $name) returns null (unknown) or one of
 *   ['t' => 'text', 'v' => string] · ['t' => 'block', 'v' => blocks] · ['t' => 'image', 'v' => image|null]
 *   ['t' => 'list', 'l' => list key, 'c' => column key]
 * $rows[list key] = list of rows, each [column key => string].
 * image = ['bytes' => string, 'ext' => png|jpeg|gif|bmp, 'w' => px, 'h' => px].
 * Block = ['type' => h1|h2|p|item|note, 'text' => string, 'tag' => optional lead].
 */
class DocxFill
{
    const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const PH = '/\{\{\s*([^{}]+?)\s*\}\}/u';
    const STYLES = array('h1' => 'OmpsH1', 'h2' => 'OmpsH2', 'note' => 'OmpsNote', 'item' => 'OmpsItem', 'p' => 'OmpsBody');
    const IMAGE_TYPES = array('png' => 'image/png', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'bmp' => 'image/bmp');

    private $zip;
    private $resolve;
    private $rows;
    private $styles = array();
    private $media = array();
    private $imageNo = 0;

    /** Text parts that may hold placeholders. */
    private static function parts(\ZipArchive $zip)
    {
        $out = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if ($n === 'word/document.xml' || preg_match('#^word/(header|footer)\d*\.xml$#', $n)) {
                $out[] = $n;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Check a package: a Word document without DTDs. Returns the placeholder names in order, or throws
     * \InvalidArgumentException when the file is not a usable .docx.
     */
    public static function scan($path)
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('not a zip');
        }
        try {
            if ($zip->locateName('word/document.xml') === false) {
                throw new \InvalidArgumentException('no document');
            }
            $names = array();
            foreach (self::parts($zip) as $part) {
                $dom = self::load((string) $zip->getFromName($part));
                $xp = self::xpath($dom);
                foreach ($xp->query('//w:p') as $p) {
                    if (preg_match_all(self::PH, self::paragraphText($p), $m)) {
                        foreach ($m[1] as $n) {
                            $names[$n] = true;
                        }
                    }
                }
            }
            return array_keys($names);
        } finally {
            $zip->close();
        }
    }

    private static function load($xml)
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \InvalidArgumentException('bad part');
        }
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            throw new \InvalidArgumentException('bad xml');
        }
        return $dom;
    }

    private static function xpath(\DOMDocument $dom)
    {
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', self::W);
        return $xp;
    }

    /** Text nodes (w:t) that belong to this paragraph, not to a nested one (text boxes). */
    private static function textNodes(\DOMElement $p)
    {
        $out = array();
        foreach ($p->getElementsByTagNameNS(self::W, 't') as $t) {
            $up = $t->parentNode;
            while ($up && !($up instanceof \DOMElement && $up->namespaceURI === self::W && $up->localName === 'p')) {
                $up = $up->parentNode;
            }
            if ($up && $up->isSameNode($p)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    private static function paragraphText(\DOMElement $p)
    {
        $s = '';
        foreach (self::textNodes($p) as $t) {
            $s .= $t->textContent;
        }
        return $s;
    }

    private static function setText(\DOMElement $t, $text)
    {
        while ($t->firstChild) {
            $t->removeChild($t->firstChild);
        }
        $t->appendChild($t->ownerDocument->createTextNode((string) $text));
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    }

    /** Move every placeholder that spans several text nodes into the first of them. */
    private static function normalise(\DOMElement $p)
    {
        for ($guard = 0; $guard < 500; $guard++) {
            $nodes = self::textNodes($p);
            $texts = array();
            $starts = array();
            $full = '';
            foreach ($nodes as $i => $t) {
                $starts[$i] = strlen($full);
                $texts[$i] = $t->textContent;
                $full .= $texts[$i];
            }
            if (strpos($full, '{{') === false || !preg_match_all(self::PH, $full, $m, PREG_OFFSET_CAPTURE)) {
                return;
            }
            $fixed = false;
            foreach ($m[0] as $hit) {
                $a = $hit[1];
                $b = $a + strlen($hit[0]);
                $i = self::nodeAt($starts, $texts, $a);
                $j = self::nodeAt($starts, $texts, $b - 1);
                if ($i === $j) {
                    continue;
                }
                self::setText($nodes[$i], substr($texts[$i], 0, $a - $starts[$i]) . $hit[0]);
                for ($k = $i + 1; $k < $j; $k++) {
                    self::setText($nodes[$k], '');
                }
                self::setText($nodes[$j], (string) substr($texts[$j], $b - $starts[$j]));
                $fixed = true;
                break;
            }
            if (!$fixed) {
                return;
            }
        }
    }

    private static function nodeAt(array $starts, array $texts, $offset)
    {
        foreach ($starts as $i => $s) {
            if ($offset >= $s && $offset < $s + strlen($texts[$i])) {
                return $i;
            }
        }
        return count($starts) - 1;
    }

    private static function ancestor(\DOMNode $n, $local)
    {
        for ($up = $n->parentNode; $up; $up = $up->parentNode) {
            if ($up instanceof \DOMElement && $up->namespaceURI === self::W && $up->localName === $local) {
                return $up;
            }
        }
        return null;
    }

    /**
     * @param string   $src     template path
     * @param callable $resolve see class comment
     * @param array    $rows    list rows
     * @return string .docx bytes
     */
    public static function fill($src, $resolve, array $rows)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'omt');
        if (!copy($src, $tmp)) {
            throw new \RuntimeException('Cannot copy the template');
        }
        $f = new self();
        $f->resolve = $resolve;
        $f->rows = $rows;
        $f->zip = new \ZipArchive();
        if ($f->zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new \InvalidArgumentException('not a zip');
        }
        try {
            $styles = (string) $f->zip->getFromName('word/styles.xml');
            foreach (self::STYLES as $type => $id) {
                if (strpos($styles, 'w:styleId="' . $id . '"') !== false) {
                    $f->styles[$type] = $id;
                }
            }
            foreach (self::parts($f->zip) as $part) {
                $f->zip->addFromString($part, $f->part($part, (string) $f->zip->getFromName($part)));
            }
            $f->finishMedia();
            $f->zip->close();
            $bytes = (string) file_get_contents($tmp);
        } catch (\Exception $e) {
            @$f->zip->close();
            @unlink($tmp);
            throw $e;
        }
        @unlink($tmp);
        return $bytes;
    }

    private function look($name)
    {
        $fn = $this->resolve;
        return $fn($name);
    }

    private function part($name, $xml)
    {
        $dom = self::load($xml);
        $xp = self::xpath($dom);
        foreach ($xp->query('//w:p') as $p) {
            self::normalise($p);
            $text = self::paragraphText($p);
            if (strpos($text, '{{') !== false && trim(preg_replace(self::PH, '', $text)) === '' && $p->getElementsByTagNameNS(self::W, 'drawing')->length === 0) {
                $p->setAttribute('data-omps-ph', '1');
            }
        }
        $this->lists($xp);
        $this->blocks($xp);
        $this->texts($xp, $name);
        foreach ($xp->query('//w:p[@data-omps-ph]') as $p) {
            $p->removeAttribute('data-omps-ph');
            if (trim(self::paragraphText($p)) !== '' || $p->getElementsByTagNameNS(self::W, 'drawing')->length > 0) {
                continue;
            }
            $parent = $p->parentNode;
            $isCell = $parent instanceof \DOMElement && $parent->localName === 'tc';
            $paras = 0;
            foreach ($parent->childNodes as $c) {
                if ($c instanceof \DOMElement && $c->localName === 'p') {
                    $paras++;
                }
            }
            $hasSect = $xp->query('./w:pPr/w:sectPr', $p)->length > 0;
            if (!$hasSect && (!$isCell || $paras > 1)) {
                $parent->removeChild($p);
            }
        }
        return $dom->saveXML();
    }

    // ---------- lists ----------

    private function firstListText(\DOMXPath $xp)
    {
        foreach ($xp->query('//w:t[contains(., "{{")]') as $t) {
            if (preg_match_all(self::PH, $t->textContent, $m)) {
                foreach ($m[1] as $n) {
                    $d = $this->look($n);
                    if ($d !== null && $d['t'] === 'list') {
                        return array($t, $d['l']);
                    }
                }
            }
        }
        return null;
    }

    private function lists(\DOMXPath $xp)
    {
        for ($guard = 0; $guard < 200; $guard++) {
            $hit = $this->firstListText($xp);
            if ($hit === null) {
                return;
            }
            list($t, $list) = $hit;
            $box = self::ancestor($t, 'tr');
            if ($box === null) {
                $box = self::ancestor($t, 'p');
            }
            if ($box === null) {
                return;
            }
            $rows = isset($this->rows[$list]) ? $this->rows[$list] : array();
            if (!$rows && $box->localName === 'tr') {
                $rows = array(array());
            }
            foreach ($rows as $i => $row) {
                $copy = $box->cloneNode(true);
                $row['no'] = isset($row['no']) ? $row['no'] : ($row ? (string) ($i + 1) : '');
                $this->fillList($copy, $row);
                $box->parentNode->insertBefore($copy, $box);
            }
            $box->parentNode->removeChild($box);
        }
    }

    /** Replace every list placeholder in a copied container (other lists become empty). */
    private function fillList(\DOMElement $box, array $row)
    {
        foreach ($box->getElementsByTagNameNS(self::W, 't') as $t) {
            $s = $t->textContent;
            if (strpos($s, '{{') === false) {
                continue;
            }
            $new = preg_replace_callback(self::PH, function ($m) use ($row) {
                $d = $this->look($m[1]);
                if ($d === null || $d['t'] !== 'list') {
                    return $m[0];
                }
                return isset($row[$d['c']]) ? (string) $row[$d['c']] : '';
            }, $s);
            if ($new !== $s) {
                $this->putText($t, $new);
            }
        }
    }

    // ---------- blocks ----------

    private function blocks(\DOMXPath $xp)
    {
        foreach ($xp->query('//w:p') as $p) {
            $text = trim(self::paragraphText($p));
            if (!preg_match('/^\{\{\s*([^{}]+?)\s*\}\}$/u', $text, $m)) {
                continue;
            }
            $d = $this->look($m[1]);
            if ($d === null || $d['t'] !== 'block') {
                continue;
            }
            $ppr = $xp->query('./w:pPr', $p)->item(0);
            $rpr = null;
            foreach (self::textNodes($p) as $t) {
                if (trim($t->textContent) !== '') {
                    $rpr = $xp->query('./w:rPr', $t->parentNode)->item(0);
                    break;
                }
            }
            foreach ($d['v'] as $b) {
                $p->parentNode->insertBefore($this->blockParagraph($p->ownerDocument, $b, $ppr, $rpr), $p);
            }
            $p->parentNode->removeChild($p);
        }
    }

    private function blockParagraph(\DOMDocument $dom, array $b, $ppr, $rpr)
    {
        $type = isset(self::STYLES[$b['type']]) ? $b['type'] : 'p';
        $styled = isset($this->styles[$type]);
        $p = $dom->createElementNS(self::W, 'w:p');
        if ($styled) {
            $pp = $dom->createElementNS(self::W, 'w:pPr');
            $st = $dom->createElementNS(self::W, 'w:pStyle');
            $st->setAttributeNS(self::W, 'w:val', $this->styles[$type]);
            $pp->appendChild($st);
            $p->appendChild($pp);
        } elseif ($ppr) {
            $copy = $ppr->cloneNode(true);
            foreach (array('sectPr', 'numPr') as $drop) {
                foreach (iterator_to_array($copy->getElementsByTagNameNS(self::W, $drop)) as $x) {
                    $x->parentNode->removeChild($x);
                }
            }
            $p->appendChild($copy);
        }
        $tag = isset($b['tag']) ? (string) $b['tag'] : '';
        $text = isset($b['text']) ? (string) $b['text'] : '';
        $heading = $type === 'h1' || $type === 'h2';
        if ($type === 'item' && $tag !== '') {
            $p->appendChild($this->run($dom, $tag . '  ', $styled ? null : $rpr, true));
        }
        $p->appendChild($this->run($dom, $text, $styled ? null : $rpr, $heading && !$styled));
        if ($type !== 'item' && $tag !== '') {
            $p->appendChild($this->run($dom, '  ' . $tag, $styled ? null : $rpr, false, true));
        }
        return $p;
    }

    private function run(\DOMDocument $dom, $text, $rpr, $bold, $plain = false)
    {
        $r = $dom->createElementNS(self::W, 'w:r');
        $pr = $rpr ? $rpr->cloneNode(true) : null;
        if ($bold || $plain) {
            if (!$pr) {
                $pr = $dom->createElementNS(self::W, 'w:rPr');
            }
            foreach (iterator_to_array($pr->getElementsByTagNameNS(self::W, 'b')) as $x) {
                $x->parentNode->removeChild($x);
            }
            if ($bold) {
                // schema order: rStyle, rFonts, then b
                $before = null;
                foreach ($pr->childNodes as $c) {
                    if ($c instanceof \DOMElement && $c->localName !== 'rStyle' && $c->localName !== 'rFonts') {
                        $before = $c;
                        break;
                    }
                }
                $pr->insertBefore($dom->createElementNS(self::W, 'w:b'), $before);
            }
        }
        if ($pr) {
            $r->appendChild($pr);
        }
        $t = $dom->createElementNS(self::W, 'w:t');
        $r->appendChild($t);
        $this->putText($t, $text);
        return $r;
    }

    // ---------- text and images ----------

    private function texts(\DOMXPath $xp, $partName)
    {
        foreach (iterator_to_array($xp->query('//w:t[contains(., "{{")]')) as $t) {
            $s = $t->textContent;
            if (!preg_match_all(self::PH, $s, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $segs = array();
            $pos = 0;
            $changed = false;
            foreach ($m[0] as $k => $hit) {
                $d = $this->look($m[1][$k][0]);
                if ($d === null || $d['t'] === 'list') {
                    continue;
                }
                $segs[] = substr($s, $pos, $hit[1] - $pos);
                if ($d['t'] === 'image') {
                    $segs[] = $d['v'] ? array('img' => $d['v']) : '';
                } elseif ($d['t'] === 'block') {
                    $lines = array();
                    foreach ($d['v'] as $b) {
                        $lines[] = trim((isset($b['tag']) && $b['tag'] !== '' ? $b['tag'] . ' ' : '') . (isset($b['text']) ? $b['text'] : ''));
                    }
                    $segs[] = implode("\n", $lines);
                } else {
                    $segs[] = (string) $d['v'];
                }
                $pos = $hit[1] + strlen($hit[0]);
                $changed = true;
            }
            if (!$changed) {
                continue;
            }
            $segs[] = substr($s, $pos);
            $this->putSegments($t, $segs, $partName);
        }
    }

    /** Replace a w:t by text (line breaks become w:br). */
    private function putText(\DOMElement $t, $text)
    {
        $this->putSegments($t, array((string) $text), null);
    }

    private function putSegments(\DOMElement $t, array $segs, $partName)
    {
        $merged = array();
        $buf = '';
        foreach ($segs as $s) {
            if (is_array($s)) {
                $merged[] = $buf;
                $merged[] = $s;
                $buf = '';
            } else {
                $buf .= $s;
            }
        }
        $merged[] = $buf;
        $simple = count($merged) === 1 && strpos($merged[0], "\n") === false;
        if ($simple) {
            self::setText($t, $merged[0]);
            return;
        }
        $dom = $t->ownerDocument;
        $parent = $t->parentNode;
        foreach ($merged as $s) {
            if (is_array($s)) {
                $node = $partName === null ? null : $this->drawing($dom, $s['img'], $partName);
                if ($node) {
                    $parent->insertBefore($node, $t);
                }
                continue;
            }
            $lines = explode("\n", str_replace("\r", '', $s));
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $parent->insertBefore($dom->createElementNS(self::W, 'w:br'), $t);
                }
                if ($line !== '') {
                    $nt = $dom->createElementNS(self::W, 'w:t');
                    self::setText($nt, $line);
                    $parent->insertBefore($nt, $t);
                }
            }
        }
        $parent->removeChild($t);
    }

    private function drawing(\DOMDocument $dom, array $img, $partName)
    {
        $ext = isset(self::IMAGE_TYPES[$img['ext']]) ? $img['ext'] : null;
        if ($ext === null || $img['bytes'] === '') {
            return null;
        }
        $this->imageNo++;
        $n = $this->imageNo;
        $file = 'media/omps_img' . $n . '.' . $ext;
        $rid = 'rIdOmps' . $n;
        $this->media[] = array('part' => $partName, 'file' => $file, 'rid' => $rid, 'bytes' => $img['bytes'], 'ext' => $ext);
        // fit into 2.5 cm x 3.5 cm keeping the proportions (1 cm = 360000 EMU)
        $boxW = 900000;
        $boxH = 1260000;
        $w = max(1, (int) $img['w']);
        $h = max(1, (int) $img['h']);
        $scale = min($boxW / $w, $boxH / $h);
        $cx = (int) round($w * $scale);
        $cy = (int) round($h * $scale);
        $id = 9000 + $n;
        $xml = '<w:drawing xmlns:w="' . self::W . '" xmlns:r="' . self::R . '" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $id . '" name="Picture ' . $id . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . $id . '" name="omps_img' . $n . '.' . $ext . '"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>';
        $tmp = self::load($xml);
        return $dom->importNode($tmp->documentElement, true);
    }

    /** Add pictures, their relationships and content types to the package. */
    private function finishMedia()
    {
        if (!$this->media) {
            return;
        }
        $byPart = array();
        foreach ($this->media as $m) {
            $this->zip->addFromString('word/' . $m['file'], $m['bytes']);
            $byPart[$m['part']][] = $m;
        }
        foreach ($byPart as $part => $list) {
            $relsName = 'word/_rels/' . basename($part) . '.rels';
            $rels = $this->zip->getFromName($relsName);
            if ($rels === false || $rels === '') {
                $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
            }
            $add = '';
            foreach ($list as $m) {
                $add .= '<Relationship Id="' . $m['rid'] . '" Type="' . self::R . '/image" Target="' . $m['file'] . '"/>';
            }
            $this->zip->addFromString($relsName, str_replace('</Relationships>', $add . '</Relationships>', $rels));
        }
        $ct = (string) $this->zip->getFromName('[Content_Types].xml');
        $add = '';
        foreach ($this->media as $m) {
            if (stripos($ct . $add, 'Extension="' . $m['ext'] . '"') === false) {
                $add .= '<Default Extension="' . $m['ext'] . '" ContentType="' . self::IMAGE_TYPES[$m['ext']] . '"/>';
            }
        }
        if ($add !== '') {
            $this->zip->addFromString('[Content_Types].xml', preg_replace('#(<Types[^>]*>)#', '$1' . $add, $ct, 1));
        }
    }
}
