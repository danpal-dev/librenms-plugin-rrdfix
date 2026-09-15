<?php

/**
 * Vista Blade minimalista para renderizar Markdown a HTML sin usar
 * paquetes Composer extras. Está pensada específicamente para los
 * documentos del plugin RrdFix (GUÍA, FAQ, INSTALACIÓN, ARQUITECTURA).
 *
 * Formatos soportados:
 *   # / ## / ###   → encabezados (también === y --- bajo una línea)
 *   - + *          → listas <ul>
 *   1.             → listas <ol>
 *   ```lang ... ```→ <pre><code>
 *   `code`         → <code>
 *   **strong**     → <strong>
 *   *italic*       → <em>
 *   > texto        → <blockquote>
 *   --- / ***      → <hr>
 *   | a | b |      → <table> con <thead>
 *   [text](url)    → <a href="url">text</a>
 */

$lines = $lines ?? [];

$inPre      = false;
$preLang    = '';
$preBuf     = [];
$inUl       = false;
$inOl       = false;
$inBlock    = false; // blockquote / paragraph
$paragraph  = [];
$listBuf    = [];

$emitParagraph  = function () use (&$paragraph, &$inBlock) {
    if ($inBlock) {
        echo '</p>';
        $inBlock = false;
    }
    if ($paragraph) {
        echo '<p>', htmlspecialchars(implode('', $paragraph), ENT_NOQUOTES, 'UTF-8'), '</p>';
        $paragraph = [];
    }
};
$closeLists = function () use (&$inUl, &$inOl, &$listBuf, &$emitParagraph) {
    $emitParagraph();
    if ($listBuf) {
        echo $inOl ? '<ol>' : '<ul>';
        foreach ($listBuf as $li) {
            echo '<li>', htmlspecialchars($li, ENT_NOQUOTES, 'UTF-8'), '</li>';
        }
        echo $inOl ? '</ol>' : '</ul>';
        $listBuf = [];
    }
    $inUl = $inOl = false;
};
$inline = function (string $s): string {
    // links [t](u)
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', function ($m) {
        $u = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
        $t = htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8');
        return "<a href=\"{$u}\" target=\"_blank\" rel=\"noopener noreferrer\">{$t}</a>";
    }, $s);
    // inline code
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) {
        return '<code>' . htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8') . '</code>';
    }, $s);
    // bold **x**
    $s = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>\1</strong>', $s);
    // italic *x* o _x_ (cuidado con fechas y números que usan -)
    $s = preg_replace('/(^|[^*])\*([^*\n][^*]*?)\*(?!\*)/u', '\1<em>\2</em>', $s);
    return $s;
};

$inTable    = false;
$tableHead  = [];
$tableRows  = [];
$flushTable = function () use (&$inTable, &$tableHead, &$tableRows, &$closeLists, &$emitParagraph) {
    if (!$inTable) return;
    $closeLists();
    $emitParagraph();
    echo '<table>';
    if ($tableHead) {
        echo '<thead><tr>';
        foreach ($tableHead as $th) {
            echo '<th>', htmlspecialchars($th, ENT_NOQUOTES, 'UTF-8'), '</th>';
        }
        echo '</tr></thead>';
    }
    if ($tableRows) {
        echo '<tbody>';
        foreach ($tableRows as $r) {
            echo '<tr>';
            foreach ($r as $td) {
                echo '<td>', htmlspecialchars($td, ENT_NOQUOTES, 'UTF-8'), '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody>';
    }
    echo '</table>';
    $inTable = false;
    $tableHead = [];
    $tableRows = [];
};

$prevForSetext = null;

foreach ($lines as $idx => $rawLine) {
    $line = rtrim($rawLine, "\r\n ");

    // ------- fenced code block -------
    if (preg_match('/^\s*```(\w*)\s*$/', $line, $m)) {
        $flushTable();
        $closeLists();
        $emitParagraph();
        if (!$inPre) {
            $inPre   = true;
            $preLang = $m[1] ?? '';
            $preBuf  = [];
        } else {
            echo '<pre><code class="language-', htmlspecialchars($preLang), '">';
            echo htmlspecialchars(implode("\n", $preBuf), ENT_NOQUOTES, 'UTF-8');
            echo '</code></pre>';
            $inPre   = false;
            $preLang = '';
            $preBuf  = [];
        }
        $prevForSetext = null;
        continue;
    }
    if ($inPre) {
        $preBuf[] = $line;
        continue;
    }

    // ------- setext headings ===== ------
    if ($prevForSetext !== null && $line !== '' && preg_match('/^\s*(=+|-+)\s*$/', $line)) {
        $level = str_starts_with(trim($line), '=') ? 1 : 2;
        $flushTable();
        $closeLists();
        $emitParagraph();
        echo "<h{$level}>", htmlspecialchars($prevForSetext, ENT_NOQUOTES, 'UTF-8'), "</h{$level}>";
        $prevForSetext = null;
        continue;
    } else {
        if ($prevForSetext !== null) {
            // Not a setext after all; treat previous as paragraph line
            $paragraph[] = ltrim($prevForSetext);
        }
        $prevForSetext = null;
    }

    // ------- blank line -------
    if (trim($line) === '') {
        $flushTable();
        $closeLists();
        $emitParagraph();
        continue;
    }

    // ------- table row -------
    if (str_contains($line, '|') && preg_match('/^\s*\|.+\|\s*$/', $line)) {
        $cells = array_map('trim', explode('|', trim($line, ' |')));
        // Separator row?
        $isSep = true;
        foreach ($cells as $c) {
            if (!preg_match('/^:?-{3,}:?$/', $c)) { $isSep = false; break; }
        }
        if ($isSep) {
            // ignore separator, head already captured
            $inTable = true;
            continue;
        }
        if (!$inTable) {
            $inTable   = true;
            $tableHead = $cells;
        } else {
            $tableRows[] = $cells;
        }
        continue;
    } else {
        $flushTable();
    }

    // ------- hr -------
    if (preg_match('/^\s*([-*_])\s*\1\s*\1[\s\1]*$/', $line)) {
        $closeLists();
        $emitParagraph();
        echo '<hr>';
        continue;
    }

    // ------- ATX heading -------
    if (preg_match('/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
        $closeLists();
        $emitParagraph();
        $level = strlen($m[1]);
        echo "<h{$level}>", $inline(htmlspecialchars($m[2], ENT_NOQUOTES, 'UTF-8')), "</h{$level}>";
        continue;
    }

    // ------- blockquote -------
    if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $m)) {
        $closeLists();
        $emitParagraph();
        $quoteText = $inline(htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8'));
        // simple: each blockquote line as its own <p> inside <blockquote>
        // To group consecutive blockquote lines, could accumulate; for simplicity open per line.
        echo '<blockquote><p>', $quoteText, '</p></blockquote>';
        continue;
    }

    // ------- unordered list -------
    if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m)) {
        if (!$inUl) {
            $flushTable();
            $emitParagraph();
            if ($inOl) { $closeLists(); }
            $inUl = true;
            $listBuf = [];
        }
        $listBuf[] = $inline(trim($m[1]));
        continue;
    }
    // ------- ordered list -------
    if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $m)) {
        if (!$inOl) {
            $flushTable();
            $emitParagraph();
            if ($inUl) { $closeLists(); }
            $inOl = true;
            $listBuf = [];
        }
        $listBuf[] = $inline(trim($m[1]));
        continue;
    }

    // ------- paragraph / setext candidate -------
    $closeLists();
    $lineTrim = ltrim($line);
    // If next line could be a setext === or --- separator, buffer as candidate.
    // We can't look at future lines simply; instead, we flush only non-candidate immediately.
    // Heuristic: short lines are candidates.
    if ($idx + 1 < count($lines) && strlen($lineTrim) > 0 && strlen($lineTrim) <= 120
        && !str_contains($lineTrim, ' ') === false) {
        // Save for setext check on next iteration
        if ($prevForSetext === null && $paragraph === []) {
            $prevForSetext = $lineTrim;
            continue;
        }
    }
    $paragraph[] = $inline($lineTrim) . ' ';
}

// Flush remaining
if ($inPre) {
    echo '<pre><code class="language-', htmlspecialchars($preLang), '">';
    echo htmlspecialchars(implode("\n", $preBuf), ENT_NOQUOTES, 'UTF-8');
    echo '</code></pre>';
}
$flushTable();
$closeLists();
if ($prevForSetext !== null) {
    $paragraph[] = ltrim($prevForSetext);
}
$emitParagraph();
