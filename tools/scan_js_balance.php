<?php
$path = __DIR__ . '/../project_details.php';
$s = file_get_contents($path);
$len = strlen($s);
$line = 1;
$col = 1;
$paren = $brace = $brack = $backtick = 0;
$positions = [];
$prev = '';
for ($i = 0; $i < $len; $i++) {
    $ch = $s[$i];
    if ($ch === "\n") { $line++; $col = 1; }
    else { $col++; }
    if ($ch === '(') $paren++;
    if ($ch === ')') $paren--;
    if ($ch === '{') $brace++;
    if ($ch === '}') $brace--;
    if ($ch === '[') $brack++;
    if ($ch === ']') $brack--;
    if ($ch === '`') $backtick = ($backtick + 1) % 2; // 0 or 1
    // record first time any counter goes negative
    if ($paren < 0 || $brace < 0 || $brack < 0) {
        $positions[] = [ 'type' => 'negative', 'line'=>$line, 'col'=>$col, 'paren'=>$paren, 'brace'=>$brace, 'brack'=>$brack, 'backtick'=>$backtick, 'idx'=>$i];
        break;
    }
}
// Also find first location where backtick is open and not closed before a certain length
// Find first location where backtick state is 1 and remains so past some later position
// We'll also compute final counts
$final = [ 'paren'=>$paren, 'brace'=>$brace, 'brack'=>$brack, 'backtick'=>$backtick ];
// Print summary
echo "Summary for $path\n";
echo "final paren={$final['paren']} brace={$final['brace']} brack={$final['brack']} backtick_open={$final['backtick']}\n";
if (!empty($positions)) {
    $p = $positions[0];
    echo "First negative at line {$p['line']} col {$p['col']} (idx {$p['idx']}) paren={$p['paren']} brace={$p['brace']} brack={$p['brack']} backtick={$p['backtick']}\n";
}
// Now find likely unterminated template literal: find first backtick that isn't closed within 5000 chars
$firstBT = strpos($s, "`");
if ($firstBT !== false) {
    $nextBT = strpos($s, "`", $firstBT+1);
    if ($nextBT === false) {
        // print context
        $start = max(0, $firstBT - 80);
        $end = min($len, $firstBT + 400);
        $ctx = substr($s, $start, $end-$start);
        $ln = substr_count(substr($s,0,$firstBT), "\n") + 1;
        echo "Unclosed backtick starting near idx $firstBT (line $ln) -- context:\n";
        echo "----\n" . $ctx . "\n----\n";
    } else {
        echo "First backtick pair found at idx $firstBT..$nextBT\n";
    }
}
// Also show lines around reported console errors: 1142 and 3431
function showLines($path, $fromLine, $toLine){
    $lines = file($path);
    $from = max(1, $fromLine-6);
    $to = min(count($lines), $toLine+6);
    echo "\nContext lines $fromLine-$toLine:\n";
    for ($i=$from;$i<=$to;$i++) {
        $num = str_pad($i,5,' ',STR_PAD_LEFT);
        echo "$num: " . rtrim($lines[$i-1]) . "\n";
    }
}
showLines($path, 1120, 1160);
showLines($path, 3418, 3440);

?>