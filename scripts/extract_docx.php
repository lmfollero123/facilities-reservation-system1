<?php
/**
 * One-off helper: extract paragraph text and basic formatting from .docx
 * Usage: php scripts/extract_docx.php <path-to-docx>
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/extract_docx.php <docx>\n");
    exit(1);
}

$path = $argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "File not found: {$path}\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($path) !== true) {
    fwrite(STDERR, "Cannot open zip: {$path}\n");
    exit(1);
}

$xml = $zip->getFromName('word/document.xml');
$stylesXml = $zip->getFromName('word/styles.xml');
$zip->close();

if ($xml === false) {
    fwrite(STDERR, "No word/document.xml in {$path}\n");
    exit(1);
}

$doc = new DOMDocument();
$doc->loadXML($xml);
$xpath = new DOMXPath($doc);
$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

$styleMap = [];
if ($stylesXml !== false) {
    $stylesDoc = new DOMDocument();
    $stylesDoc->loadXML($stylesXml);
    $sx = new DOMXPath($stylesDoc);
    $sx->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    foreach ($sx->query('//w:style') as $styleNode) {
        $id = $styleNode->getAttribute('w:styleId');
        if ($id === '') {
            continue;
        }
        $nameNode = $sx->query('w:name', $styleNode)->item(0);
        $name = $nameNode ? $nameNode->getAttribute('w:val') : '';
        $rPr = $sx->query('w:rPr', $styleNode)->item(0);
        $font = '';
        $size = '';
        if ($rPr) {
            $rFonts = $sx->query('w:rFonts', $rPr)->item(0);
            if ($rFonts) {
                $font = $rFonts->getAttribute('w:ascii') ?: $rFonts->getAttribute('w:hAnsi');
            }
            $sz = $sx->query('w:sz', $rPr)->item(0);
            if ($sz) {
                $size = $sz->getAttribute('w:val');
            }
        }
        $styleMap[$id] = ['name' => $name, 'font' => $font, 'size' => $size];
    }
}

function wAttr(DOMNode $node, string $local): string
{
    return $node->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', $local);
}

$paras = $xpath->query('//w:p');
$num = 0;
foreach ($paras as $p) {
    $text = '';
    foreach ($xpath->query('.//w:t', $p) as $t) {
        $text .= $t->textContent;
    }
    $text = trim($text);
    if ($text === '') {
        continue;
    }
    $num++;

    $pStyle = '';
    $spacing = '';
    $pPr = $xpath->query('w:pPr', $p)->item(0);
    if ($pPr) {
        $ps = $xpath->query('w:pStyle', $pPr)->item(0);
        if ($ps) {
            $pStyle = wAttr($ps, 'val');
        }
        $sp = $xpath->query('w:spacing', $pPr)->item(0);
        if ($sp) {
            $spacing = sprintf(
                'before=%s after=%s line=%s lineRule=%s',
                wAttr($sp, 'before'),
                wAttr($sp, 'after'),
                wAttr($sp, 'line'),
                wAttr($sp, 'lineRule')
            );
        }
    }

    $font = '';
    $size = '';
    $bold = false;
    $r = $xpath->query('.//w:r', $p)->item(0);
    if ($r) {
        $rPr = $xpath->query('w:rPr', $r)->item(0);
        if ($rPr) {
            $rFonts = $xpath->query('w:rFonts', $rPr)->item(0);
            if ($rFonts) {
                $font = wAttr($rFonts, 'ascii') ?: wAttr($rFonts, 'hAnsi');
            }
            $sz = $xpath->query('w:sz', $rPr)->item(0);
            if ($sz) {
                $size = wAttr($sz, 'val');
            }
            $bold = $xpath->query('w:b', $rPr)->length > 0;
        }
    }

    $styleName = $styleMap[$pStyle]['name'] ?? '';
    $styleFont = $styleMap[$pStyle]['font'] ?? '';
    $styleSize = $styleMap[$pStyle]['size'] ?? '';

    echo "{$num}|pStyle={$pStyle}|styleName={$styleName}|spacing={$spacing}|font={$font}|sz={$size}|bold=" . ($bold ? '1' : '0') . "|styleFont={$styleFont}|styleSz={$styleSize}|{$text}\n";
}

echo "TOTAL_PARAS={$num}\n";
