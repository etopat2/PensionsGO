<?php

require_once __DIR__ . '/../lib/pdf_library.php';

function dmCol(int $i): string {
    $n=''; $i++;
    while($i>0){$m=($i-1)%26; $n=chr(65+$m).$n; $i=(int)(($i-$m-1)/26);} return $n;
}
function dmXml($v): string {
    $t=(string)$v;
    if(function_exists('mb_convert_encoding')){ $c=@mb_convert_encoding($t,'UTF-8','UTF-8'); if($c!==false){$t=$c;} }
    $t=preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u','',$t) ?? '';
    return htmlspecialchars($t, ENT_XML1|ENT_QUOTES, 'UTF-8');
}
function dmLen(string $v): int { return function_exists('mb_strlen') ? (int)mb_strlen($v,'UTF-8') : strlen($v); }
function dmPdfEsc(string $t): string {
    $t=str_replace('\\','\\\\',$t); $t=str_replace('(','\\(',$t); $t=str_replace(')','\\)',$t);
    return preg_replace('/[\x00-\x1F\x7F]/u','',$t) ?? '';
}
function dmWrap(string $text,int $max): array {
    $clean=preg_replace('/\s+/u',' ',trim($text)) ?? '';
    if($clean===''){return [''];}
    $words=preg_split('/\s+/u',$clean) ?: [$clean]; $lines=[]; $cur=''; $max=max(1,$max);
    foreach($words as $word){
        if(dmLen($word)>$max){
            if($cur!==''){
                $lines[]=$cur;
                $cur='';
            }
            $chunk='';
            foreach((preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY) ?: [$word]) as $ch){
                if(dmLen($chunk.$ch)>$max){
                    if($chunk!==''){$lines[]=$chunk;}
                    $chunk=$ch;
                } else {
                    $chunk.=$ch;
                }
            }
            if($chunk!==''){
                $lines[]=$chunk;
            }
            continue;
        }
        $cand=$cur===''?$word:($cur.' '.$word);
        if(dmLen($cand)<= $max){$cur=$cand;} else { if($cur!==''){$lines[]=$cur;} $cur=$word; }
    }
    if($cur!==''){$lines[]=$cur;} return $lines ?: [$clean];
}
function dmWrapToWidth(string $text,float $fontSize,float $maxWidth): array {
    $clean = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    if ($clean === '') {
        return [''];
    }

    $words = preg_split('/\s+/u', $clean) ?: [$clean];
    $lines = [];
    $current = '';
    $maxWidth = max(24.0, $maxWidth);

    foreach ($words as $word) {
        $word = (string)$word;
        if (dmTextW($word, $fontSize) > $maxWidth) {
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            $chunk = '';
            foreach ((preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [$word]) as $character) {
                $candidate = $chunk . $character;
                if ($chunk !== '' && dmTextW($candidate, $fontSize) > $maxWidth) {
                    $lines[] = $chunk;
                    $chunk = (string)$character;
                } else {
                    $chunk = $candidate;
                }
            }
            if ($chunk !== '') {
                $lines[] = $chunk;
            }
            continue;
        }

        $candidate = $current === '' ? $word : ($current . ' ' . $word);
        if ($current === '' || dmTextW($candidate, $fontSize) <= $maxWidth) {
            $current = $candidate;
        } else {
            $lines[] = $current;
            $current = $word;
        }
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return $lines ?: [$clean];
}
function dmLongestTokenLen(string $text): int {
    $clean = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    if ($clean === '') {
        return 0;
    }
    $tokens = preg_split('/\s+/u', $clean) ?: [$clean];
    $longest = 0;
    foreach ($tokens as $token) {
        $longest = max($longest, dmLen((string)$token));
    }
    return $longest;
}
function dmPdfFitFontSize(string $text, float $cellWidth, float $preferredSize, float $minSize = 6.0): float {
    $textWidth = dmTextW($text, $preferredSize);
    $usable = max(1.0, $cellWidth - 6.0);
    if ($textWidth <= $usable || $textWidth <= 0) {
        return $preferredSize;
    }
    $scaled = $preferredSize * ($usable / $textWidth);
    return max($minSize, min($preferredSize, $scaled));
}
function dmTextW(string $txt,float $size): float { return max(0,dmLen($txt))*($size*0.52); }
function dmPdfText(string $font,float $size,float $x,float $y,string $txt,float $r,float $g,float $b,float $wordSpacing=0.0): string {
    $spacingCommand = abs($wordSpacing) > 0.0001
        ? number_format($wordSpacing,3,'.','')." Tw\n"
        : "0 Tw\n";
    return "BT\n/$font ".number_format($size,2,'.','')." Tf\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." rg\n".$spacingCommand."1 0 0 1 ".number_format($x,2,'.','').' '.number_format($y,2,'.','')." Tm\n(".dmPdfEsc($txt).") Tj\nET\n";
}
function dmRectFill(float $x,float $y,float $w,float $h,float $r,float $g,float $b): string { return "q\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." rg\n".number_format($x,2,'.','').' '.number_format($y,2,'.','').' '.number_format($w,2,'.','').' '.number_format($h,2,'.','')." re f\nQ\n"; }
function dmRectStroke(float $x,float $y,float $w,float $h,float $r,float $g,float $b,float $lw=0.5): string { return "q\n".number_format($lw,3,'.','')." w\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." RG\n".number_format($x,2,'.','').' '.number_format($y,2,'.','').' '.number_format($w,2,'.','').' '.number_format($h,2,'.','')." re S\nQ\n"; }
function dmLine(float $x1,float $y1,float $x2,float $y2,float $r,float $g,float $b,float $lw=0.35): string { return "q\n".number_format($lw,3,'.','')." w\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." RG\n".number_format($x1,2,'.','').' '.number_format($y1,2,'.','')." m\n".number_format($x2,2,'.','').' '.number_format($y2,2,'.','')." l S\nQ\n"; }
function dmEnsureDeps(mysqli $conn,string $key): void {
    if($key==='file_registry'){ if(function_exists('ensureFileMovementTables'))ensureFileMovementTables($conn); if(function_exists('ensureStaffDueExtendedColumns'))ensureStaffDueExtendedColumns($conn); if(function_exists('ensureStaffDocumentsTable'))ensureStaffDocumentsTable($conn); if(function_exists('ensureLifeCertificateTables'))ensureLifeCertificateTables($conn); if(function_exists('syncCurrentYearLifeCertificateStatus'))syncCurrentYearLifeCertificateStatus($conn); if(function_exists('maybeReconcileAllActivePayrollCycles'))maybeReconcileAllActivePayrollCycles($conn); }
    elseif($key==='tasks' && function_exists('ensureTasksTable')){ ensureTasksTable($conn); }
    elseif($key==='workflow_logs' && function_exists('ensureWorkflowLogsTable')){ ensureWorkflowLogsTable($conn); }
    elseif($key==='task_delegation_logs' && function_exists('ensureTaskDelegationLogsTable')){ ensureTaskDelegationLogsTable($conn); }
    elseif($key==='file_movements' && function_exists('ensureFileMovementTables')){ ensureFileMovementTables($conn); }
    elseif($key==='claims_ledger' && function_exists('ensureArrearsAndBudgetTables')){ ensureArrearsAndBudgetTables($conn); }
    elseif($key==='feedback_submissions' && function_exists('ensureFeedbackWorkflowTables')){ ensureFeedbackWorkflowTables($conn); }
    elseif($key==='registry_recycle_bin' && function_exists('ensureRegistryRecycleBinTable')){ ensureRegistryRecycleBinTable($conn); }
    elseif($key==='payroll_cycles' && function_exists('ensurePayrollManagementTables')){ ensurePayrollManagementTables($conn); }
}
function dmFmt(mysqli $conn,string $col,$value): string {
    if($value===null){return '';} $text=trim((string)$value); if($text===''){return '';}
    if(in_array($col,['role_key','user_role','actor_role','assigned_role'],true)){ $label=getRoleLabel($conn, normalizeRoleKey($text)); return $label!==''?$label:$text; }
    if(in_array($col,['activity_type','task_type'],true)){ return ucwords(str_replace('_',' ',$text)); }
    if(preg_match('/(^|_)(amount|salary|pension|gratuity|total|balance|paid|expected)(_|$)/i',$col) && is_numeric($text)){ return number_format((float)$text,2,'.',''); }
    if(in_array($col,['document_count','period_year','period_month','payroll_month','payroll_year','matched_count','unmatched_count'],true) && is_numeric($text)){ return (string)(0+$text); }
    return $text;
}
function dmPayload(mysqli $conn,array $def,array $raw,string $by): array {
    $cols=$def['columns'] ?? []; $headers=['sn'=>'S/N'] + $cols; $rows=[]; $samples=[]; $sn=1; $texts=array_fill_keys((array)($def['text_columns'] ?? []), true);
    foreach($raw as $r){ $row=['sn'=>(string)$sn++]; foreach($cols as $k=>$lab){ $row[$k]=dmFmt($conn,$k,$r[$k] ?? ''); if($row[$k] !== ''){$samples[$k]=max($samples[$k] ?? 0, dmLen($row[$k]));} } $rows[]=$row; }
    return ['title'=>'UPS PensionsGo - '.($def['label'] ?? 'Data Export'),'sheet_name'=>(string)($def['label'] ?? 'Export'),'headers'=>$headers,'rows'=>$rows,'generated_at'=>date('Y-m-d H:i:s'),'generated_by'=>$by,'text_columns'=>$texts,'pdf_mode'=>(string)($def['pdf_mode'] ?? 'table'),'sample_lengths'=>$samples,'meta_lines'=>(array)($def['meta_lines'] ?? [])];
}
function dmBuildXlsxReport(array $p): array {
    $headers=$p['headers']; $rows=$p['rows']; $texts=$p['text_columns']; $sheetRows=[]; $merges=[]; $r=1; $maxCols=count($headers);
    $customAligns = is_array($p['aligns'] ?? null) ? $p['aligns'] : [];
    $add=function(array $cells, ?float $h=null) use (&$sheetRows,&$r): int { $cur=$r; $row=['r'=>$r,'cells'=>$cells]; if($h!==null && $h>0){$row['h']=$h;} $sheetRows[]=$row; $r++; return $cur; };
    $t=$add([['v'=>$p['title'],'s'=>1,'t'=>'s']],26.0); $merges[]='A'.$t.':'.dmCol($maxCols-1).$t;
    $g1=$add([['v'=>'Generated On','s'=>2,'t'=>'s'],['v'=>$p['generated_at'],'s'=>3,'t'=>'s']],18.0); $merges[]='B'.$g1.':'.dmCol($maxCols-1).$g1;
    $g2=$add([['v'=>'Generated By','s'=>2,'t'=>'s'],['v'=>$p['generated_by'],'s'=>3,'t'=>'s']],18.0); $merges[]='B'.$g2.':'.dmCol($maxCols-1).$g2;
    foreach((array)($p['meta_lines'] ?? []) as $metaLine){ $gm=$add([['v'=>'Filters','s'=>2,'t'=>'s'],['v'=>(string)$metaLine,'s'=>3,'t'=>'s']],18.0); $merges[]='B'.$gm.':'.dmCol($maxCols-1).$gm; }
    $add([],6.0);
    $head=[]; $headerIndex=0;
    foreach($headers as $key=>$h){
        $style=5;
        $align = strtolower((string)($customAligns[$key] ?? ''));
        if($align==='left'){ $style=9; }
        elseif($align==='right'){ $style=10; }
        $head[]=['v'=>$h,'s'=>$style,'t'=>'s'];
        $headerIndex++;
    }
    $add($head,20.0);
    foreach($rows as $row){ $cells=[]; foreach($row as $k=>$v){ $isText=isset($texts[$k]); $num=!$isText && is_numeric($v) && !preg_match('/^0\d+/',(string)$v); if($k==='sn'){$cells[]=['v'=>(int)$v,'s'=>7,'t'=>'n'];} elseif($num){$cells[]=['v'=>(float)$v,'s'=>8,'t'=>'n'];} else {$cells[]=['v'=>(string)$v,'s'=>6,'t'=>'s'];} } $add($cells,18.0); }
    $widths=array_fill(0,$maxCols,10.0);
    foreach($sheetRows as $row){ $rowRef=(int)($row['r'] ?? 0); foreach((array)($row['cells'] ?? []) as $i=>$cell){ if(($rowRef<=3 && $i>1) || ($rowRef===1 && $i>0))continue; $raw=trim((string)($cell['v'] ?? '')); if($raw==='')continue; $long=0; foreach(preg_split('/\R/u',$raw) ?: [$raw] as $line){ $long=max($long, dmLen(trim((string)$line))); } $widths[$i]=max($widths[$i], min(42.0, max(8.0, $long+2.0))); } }
    return ['rows'=>$sheetRows,'merges'=>$merges,'max_cols'=>$maxCols,'column_widths'=>$widths,'orientation'=>($maxCols>8 || array_sum($widths)>120)?'landscape':'portrait'];
}
function dmXlsxBinary(array $report,string $sheetName): string {
    if(!class_exists('ZipArchive')){ throw new RuntimeException('ZipArchive is required for XLSX export.'); }
    $rows=(array)($report['rows'] ?? []); $merges=(array)($report['merges'] ?? []); $maxCols=max(1,(int)($report['max_cols'] ?? 1)); $widths=(array)($report['column_widths'] ?? []); $orientation=strtolower((string)($report['orientation'] ?? 'portrait'))==='landscape'?'landscape':'portrait';
    $lastCol=dmCol($maxCols-1); $lastRow=1; $rowsXml='';
    foreach($rows as $row){ $rowRef=(int)($row['r'] ?? ($lastRow+1)); $lastRow=max($lastRow,$rowRef); $h=(float)($row['h'] ?? 0); $attrs=' r="'.$rowRef.'"'; if($h>0){$attrs.=' ht="'.number_format($h,2,'.','').'" customHeight="1"';} $rowsXml.='<row'.$attrs.'>';
        foreach((array)($row['cells'] ?? []) as $i=>$cell){ $v=$cell['v'] ?? ''; $ref=dmCol($i).$rowRef; $s=max(0,(int)($cell['s'] ?? 0)); $t=(string)($cell['t'] ?? 's'); if($v===null || $v===''){ $rowsXml.='<c r="'.$ref.'" s="'.$s.'"/>'; continue; } if($t==='n' && is_numeric($v)){ $rowsXml.='<c r="'.$ref.'" s="'.$s.'"><v>'.dmXml((string)$v).'</v></c>'; } else { $rowsXml.='<c r="'.$ref.'" s="'.$s.'" t="inlineStr"><is><t>'.dmXml((string)$v).'</t></is></c>'; } }
        $rowsXml.='</row>'; }
    $colsXml='<cols>'; foreach($widths as $i=>$w){ $col=$i+1; $colsXml.='<col min="'.$col.'" max="'.$col.'" width="'.number_format(max(8.0,(float)$w),2,'.','').'" customWidth="1"/>'; } $colsXml.='</cols>';
    $mergeXml=''; if(!empty($merges)){ $mergeXml='<mergeCells count="'.count($merges).'">'; foreach($merges as $m){ $mergeXml.='<mergeCell ref="'.dmXml((string)$m).'"/>'; } $mergeXml.='</mergeCells>'; }
    $sheetXml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:'.$lastCol.$lastRow.'"/><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="16"/>'.$colsXml.'<sheetData>'.$rowsXml.'</sheetData>'.$mergeXml.'<printOptions horizontalCentered="0" verticalCentered="0" headings="0" gridLines="0"/><pageMargins left="0.30" right="0.30" top="0.35" bottom="0.35" header="0.30" footer="0.30"/><pageSetup paperSize="9" orientation="'.$orientation.'" fitToWidth="1" fitToHeight="1"/></worksheet>';
    $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><color rgb="FF1F2937"/><name val="Tahoma"/><family val="2"/></font><font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Tahoma"/><family val="2"/></font><font><b/><sz val="11"/><color rgb="FF111827"/><name val="Tahoma"/><family val="2"/></font></fonts><fills count="6"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF741A2D"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF4CC"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFA32A3E"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF8F4F5"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFB44556"/></left><right style="thin"><color rgb="FFB44556"/></right><top style="thin"><color rgb="FFB44556"/></top><bottom style="thin"><color rgb="FFB44556"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="11"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf><xf numFmtId="4" fontId="0" fillId="5" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $safe = trim($sheetName) !== '' ? str_replace(['\\', '/', '?', '*', '[', ']', ':'], '-', trim($sheetName)) : 'Export';
    $safe=trim((string)$safe); if($safe==='')$safe='Export'; if(dmLen($safe)>31){ $safe=function_exists('mb_substr')?mb_substr($safe,0,31,'UTF-8'):substr($safe,0,31); }
    $workbook='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.dmXml($safe).'" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $wbRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $rootRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $types='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    $tmp=tempnam(sys_get_temp_dir(),'dm_export_xlsx_'); if($tmp===false){ throw new RuntimeException('Failed to allocate temp file for XLSX export.'); }
    $zip=new ZipArchive(); if($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE)!==true){ @unlink($tmp); throw new RuntimeException('Failed to create XLSX archive.'); }
    $zip->addFromString('[Content_Types].xml',$types); $zip->addFromString('_rels/.rels',$rootRels); $zip->addFromString('xl/workbook.xml',$workbook); $zip->addFromString('xl/_rels/workbook.xml.rels',$wbRels); $zip->addFromString('xl/styles.xml',$styles); $zip->addFromString('xl/worksheets/sheet1.xml',$sheetXml); $zip->close();
    $bin=(string)file_get_contents($tmp); @unlink($tmp); if($bin===''){ throw new RuntimeException('Generated XLSX file is empty.'); } return $bin;
}
function dmTableBlocks(array $p): array {
    $blocks=[['style'=>'title','text'=>$p['title']],['style'=>'meta','text'=>'Generated: '.$p['generated_at']],['style'=>'meta','text'=>'Generated By: '.$p['generated_by']],['style'=>'meta','text'=>'Records: '.count($p['rows'])]];
    foreach((array)($p['meta_lines'] ?? []) as $metaLine){ $blocks[]=['style'=>'meta','text'=>(string)$metaLine]; }
    $blocks[]=['style'=>'spacer','text'=>'']; $blocks[]=['style'=>'section','text'=>'Export Data'];
    $keys=array_keys($p['headers']); $labels=array_values($p['headers']); $widths=[]; $tokenSamples=[];
    $customAligns = is_array($p['aligns'] ?? null) ? $p['aligns'] : [];
    foreach($keys as $k){
        $header = (string)($p['headers'][$k] ?? '');
        $tokenSamples[$k] = dmLongestTokenLen($header);
    }
    foreach($p['rows'] as $row){
        foreach($keys as $k){
            $tokenSamples[$k] = max($tokenSamples[$k] ?? 0, dmLongestTokenLen((string)($row[$k] ?? '')));
        }
    }
    foreach($keys as $k){
        $headerLen = dmLen((string)($p['headers'][$k] ?? ''));
        $tokenLen = (int)($tokenSamples[$k] ?? $headerLen);
        if($k==='sn'){
            $widths[] = 4;
        } elseif(preg_match('/(amount|salary|pension|gratuity|total|balance|paid|expected|count)/i',$k)) {
            $widths[] = min(18, max(10, $tokenLen + 2));
        } elseif(preg_match('/(details|documents|metadata|address|reason|description)/i',$k)) {
            $widths[] = min(24, max(14, max($headerLen, (int)ceil($tokenLen * 0.9))));
        } elseif(preg_match('/(file|phone|box|number|date|status|role|type|actor|user|name|title)/i',$k)) {
            $widths[] = min(16, max(8, max($headerLen, $tokenLen + 1)));
        } else {
            $widths[] = min(15, max(7, max($headerLen, $tokenLen + 1)));
        }
    }
    $aligns=[]; foreach($keys as $k){ if($k==='sn'){$aligns[]='center';} elseif(preg_match('/(amount|salary|pension|gratuity|total|balance|paid|expected|count)/i',$k)){$aligns[]='right';} elseif(preg_match('/(date|status|role|type|month|year)/i',$k)){$aligns[]='center';} else {$aligns[]='left';} }
    foreach($keys as $i=>$k){
        $override = strtolower((string)($customAligns[$k] ?? ''));
        if(in_array($override, ['left','center','right'], true)){
            $aligns[$i] = $override;
        }
    }
    $blocks[]=['style'=>'grid_header','cells'=>$labels,'widths'=>$widths,'aligns'=>$aligns];
    if(empty($p['rows'])){ $blocks[]=['style'=>'detail','text'=>'No records were available for export.']; return $blocks; }
    foreach($p['rows'] as $row){ $cells=[]; foreach($keys as $k){ $cells[]=(string)($row[$k] ?? ''); } $blocks[]=['style'=>'grid_row','cells'=>$cells,'widths'=>$widths,'aligns'=>$aligns]; }
    return $blocks;
}
function dmRegistryBlocks(array $p): array {
    $blocks=[['style'=>'title','text'=>$p['title']],['style'=>'meta','text'=>'Generated: '.$p['generated_at']],['style'=>'meta','text'=>'Generated By: '.$p['generated_by']],['style'=>'meta','text'=>'Records: '.count($p['rows'])],['style'=>'spacer','text'=>'']];
    if(empty($p['rows'])){ $blocks[]=['style'=>'detail','text'=>'No pension file registry records were available for export.']; return $blocks; }
    $groups=[
        'Identity & Indexing'=>['file_number','computer_number','supplier_number','box_number','title','surname','first_name','full_name','gender','station'],
        'Status & Lifecycle'=>['living_status','life_certificate_status','payroll_status','pay_type','availability_status','availability_reason','date_of_birth','date_of_enlistment','retirement_date','retirement_type','date_on_15_years','period_to_15_years','period_from_15_years'],
        'Contact & Banking'=>['phone_number','email_address','postal_address','next_of_kin','next_of_kin_contact','bank_name','bank_account','bank_branch'],
        'Benefits Snapshot'=>['monthly_salary','length_of_service_months','annual_salary','reduced_pension','full_pension','commuted_gratuity'],
        'Compliance & Documents'=>['tin_number','nin_number','document_count','uploaded_documents','additional_metadata','recorded_at']
    ];
    foreach($p['rows'] as $row){ $headline=trim((string)($row['file_number'] ?? '').' - '.(string)($row['full_name'] ?? '')); $blocks[]=['style'=>'section','text'=>'Record '.(string)($row['sn'] ?? '').': '.($headline!==''?$headline:'Registry Record')];
        foreach($groups as $title=>$keys){ $blocks[]=['style'=>'subsection','text'=>$title]; $pair=[]; foreach($keys as $k){ $v=trim((string)($row[$k] ?? '')); if($v==='')continue; $pair[]=[(string)($p['headers'][$k] ?? $k),$v]; if(count($pair)===2){ $blocks[]=['style'=>'grid_row','cells'=>[$pair[0][0],$pair[0][1],$pair[1][0],$pair[1][1]],'widths'=>[15,35,15,35],'aligns'=>['left','left','left','left']]; $pair=[]; } }
            if(!empty($pair)){ $blocks[]=['style'=>'grid_row','cells'=>[$pair[0][0],$pair[0][1],'',''],'widths'=>[15,35,15,35],'aligns'=>['left','left','left','left']]; }
        }
        $blocks[]=['style'=>'spacer','text'=>''];
    }
    return $blocks;
}
function dmPdfBinary(array $blocks,string $orientation='portrait',string $footer='UPS PensionsGo Data Export'): string {
    return pgoRenderBlocksPdf($blocks, $orientation, [
        'title' => $footer,
        'footer' => $footer,
    ]);
}

function dmWriteExportArtifact(array $export, string $format, string $filePath): void {
    if($format==='csv'){
        $h=fopen($filePath,'wb'); if($h===false){ throw new RuntimeException('Unable to create CSV export.'); }
        fputcsv($h,[$export['title']]); fputcsv($h,['Generated On',$export['generated_at']]); fputcsv($h,['Generated By',$export['generated_by']]);
        foreach((array)($export['meta_lines'] ?? []) as $metaLine){ fputcsv($h,['Filters', (string)$metaLine]); }
        fputcsv($h,[]); fputcsv($h,array_values($export['headers'])); foreach($export['rows'] as $row){ fputcsv($h,array_values($row)); } fclose($h);
    } elseif($format==='json'){
        file_put_contents($filePath, json_encode(['report_title'=>$export['title'],'generated_at'=>$export['generated_at'],'generated_by'=>$export['generated_by'],'meta_lines'=>$export['meta_lines'] ?? [],'columns'=>$export['headers'],'rows'=>$export['rows']], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    } elseif($format==='pdf'){
        $pdfMode=(string)($export['pdf_mode'] ?? 'table');
        $rowCount=count((array)($export['rows'] ?? []));
        $columnCount=count((array)($export['headers'] ?? []));
        if($pdfMode==='table' && ($rowCount * $columnCount) > 15000){
            throw new RuntimeException('The current PDF selection is too large. Reduce the selected fields or apply narrower filters before exporting.');
        }
        $blocks=$export['pdf_mode']==='detail' ? dmRegistryBlocks($export) : dmTableBlocks($export);
        $orientation=$export['pdf_mode']==='detail' ? 'portrait' : ((count($export['headers'])>8)?'landscape':'portrait');
        file_put_contents($filePath, dmPdfBinary($blocks,$orientation,$export['title']));
    } else {
        file_put_contents($filePath, dmXlsxBinary(dmBuildXlsxReport($export), $export['sheet_name']));
    }
}

function dmExecuteConfiguredDataExport(mysqli $conn, array $actor, string $datasetKey, string $format, array $payload = [], string $notes = 'Export generated.'): array {
    ensureDataManagementInfrastructure($conn);
    $datasetKey = strtolower(trim($datasetKey));
    $format = strtolower(trim($format));
    if(!in_array($format,['csv','xlsx','json','pdf'],true)){$format='xlsx';}

    if (in_array($datasetKey, ['tasks', 'workflow_logs'], true) && !getAppSettingBool($conn, 'workflow_logs_export_enabled', true)) {
        throw new RuntimeException('Workflow exports are currently disabled.');
    }
    if ($datasetKey === 'task_delegation_logs' && !getAppSettingBool($conn, 'task_delegation_export_enabled', true)) {
        throw new RuntimeException('Task delegation exports are currently disabled.');
    }

    dmEnsureDeps($conn,$datasetKey);
    $customDef=dmBuildConfiguredExportDefinition($conn,$datasetKey,$payload);
    $export=dmPayload($conn,$customDef,(array)($customDef['rows'] ?? []),(string)($actor['user_name'] ?? 'System User'));
    $timestamp=date('Ymd_His');
    $baseName=$datasetKey.'_export_'.$timestamp;
    $dir=getDataExportStoragePath();
    $filePath=$dir.DIRECTORY_SEPARATOR.$baseName.'.'.$format;
    $fileName=basename($filePath);

    dmWriteExportArtifact($export, $format, $filePath);

    $size=is_file($filePath)?(int)filesize($filePath):0;
    $datasetLabel=$customDef['label'] ?? $datasetKey;
    recordDataExportRun($conn,[
        'dataset_key'=>$datasetKey,
        'dataset_label'=>$datasetLabel,
        'export_format'=>$format,
        'file_name'=>$fileName,
        'file_path'=>$filePath,
        'file_size_bytes'=>$size,
        'filters_json'=>$payload,
        'status'=>'success',
        'notes'=>$notes,
        'created_by'=>$actor['user_id'] ?? '',
        'created_by_name'=>$actor['user_name'] ?? 'System User',
        'created_by_role'=>$actor['user_role'] ?? ''
    ]);
    logAuditEvent($conn,[
        'actor_id'=>$actor['user_id'] ?? '',
        'actor_name'=>$actor['user_name'] ?? 'System User',
        'actor_role'=>$actor['user_role'] ?? '',
        'action'=>'data_export_generated',
        'entity_type'=>'data_export',
        'entity_id'=>$datasetKey,
        'details'=>[
            'format'=>$format,
            'row_count'=>count($export['rows']),
            'file_name'=>$fileName,
            'source_notes'=>$notes
        ]
    ]);

    return [
        'dataset_label' => $datasetLabel,
        'row_count' => count($export['rows']),
        'file_name' => $fileName,
        'file_size_bytes' => $size,
        'download_url' => '../backend/api/download_data_artifact.php?type=export&file='.rawurlencode($fileName)
    ];
}
