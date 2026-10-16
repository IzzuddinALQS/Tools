<?php

namespace App\Services\Source\Sql;

/**
 * Menentukan apakah sebuah SELECT membaca SATU tabel Oracle (sehingga nama
 * tabel & kolom di PG A boleh identik), ataukah JOIN/agregasi (sehingga user
 * WAJIB memberi nama tabel tujuan sendiri).
 *
 * Aturan 1 & 2.
 *
 * Syarat mode identitas (semua harus terpenuhi):
 *   - diawali SELECT (CTE / WITH tidak diperbolehkan, karena sumbernya bisa
 *     lebih dari satu tabel)
 *   - tidak ada JOIN, tidak ada koma di FROM (implicit join)
 *   - FROM bukan subquery
 *   - tidak ada GROUP BY / HAVING / DISTINCT / UNION / MINUS / INTERSECT
 *   - tidak ada fungsi agregat, tidak ada klausa analitik (OVER)
 *   - tidak ada PIVOT / UNPIVOT / CONNECT BY / MODEL
 *   - setiap item select list adalah referensi kolom biasa, tanpa ALIAS
 *
 * Prinsipnya: bila ragu, TOLAK mode identitas dan minta user memberi nama
 * tabel sendiri. Salah menolak jauh lebih aman daripada salah menebak.
 */
class BaseTableDetector
{
    /** Kata kunci yang menutupi klausa FROM. */
    private const FROM_TERMINATORS = [
        'WHERE', 'GROUP', 'ORDER', 'HAVING', 'OFFSET', 'FETCH',
        'UNION', 'INTERSECT', 'MINUS', 'CONNECT', 'START', 'MODEL',
        'FOR', 'WINDOW', 'SAMPLE', 'RETURNING',
    ];

    /** Ciri query yang TIDAK boleh memakai mode identitas. */
    private const SHAPE_BREAKERS = [
        'JOIN' => 'menggunakan JOIN',
        'DISTINCT' => 'menggunakan DISTINCT',
        'UNION' => 'menggunakan UNION',
        'INTERSECT' => 'menggunakan INTERSECT',
        'MINUS' => 'menggunakan MINUS',
        'HAVING' => 'menggunakan HAVING',
        'PIVOT' => 'menggunakan PIVOT',
        'UNPIVOT' => 'menggunakan UNPIVOT',
        'CONNECT' => 'menggunakan CONNECT BY',
        'MODEL' => 'menggunakan MODEL clause',
        'MATCH_RECOGNIZE' => 'menggunakan MATCH_RECOGNIZE',
    ];

    /** Fungsi agregat / analitik Oracle. */
    private const AGGREGATES = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'LISTAGG', 'MEDIAN',
        'STDDEV', 'STDDEV_POP', 'STDDEV_SAMP', 'VAR_POP', 'VAR_SAMP',
        'VARIANCE', 'GROUPING', 'RANK', 'DENSE_RANK', 'PERCENT_RANK',
        'CUME_DIST', 'NTILE', 'ROW_NUMBER', 'COLLECT', 'JSON_ARRAYAGG',
        'JSON_OBJECTAGG', 'XMLAGG',
    ];

    /**
     * Kata kunci yang TIDAK mungkin menjadi alias implisit.
     * Mencegah `CAST(x AS CHAR(10))` salah dibaca sebagai alias.
     */
    private const NON_ALIAS_KEYWORDS = [
        'AS', 'AND', 'OR', 'NOT', 'NULL', 'IS', 'IN', 'LIKE', 'BETWEEN',
        'WHEN', 'THEN', 'ELSE', 'END', 'CASE', 'BY', 'FROM', 'SELECT',
        'DISTINCT', 'CAST', 'CHAR', 'CHARACTER', 'VARYING', 'TIMESTAMP',
        'INTERVAL', 'PRECISION', 'SET', 'TO', 'ON', 'DESC', 'ASC',
        'WITH', 'OVER', 'PARTITION', 'ROWS', 'RANGE', 'ORDER',
    ];

    public function analyze(string $sql): QueryShape
    {
        $scanner = SqlScanner::scan($sql);
        $words = $scanner->words();
        $reasons = [];

        // --- CTE ---
        if (in_array('WITH', $words, true)) {
            $reasons[] = 'Query memakai CTE (WITH).';
        }

        // --- klausa kontroversial ---
        foreach (self::SHAPE_BREAKERS as $keyword => $description) {
            if (in_array($keyword, $words, true)) {
                $reasons[] = 'Query '.$description.'.';
            }
        }

        if ($scanner->hasSequence('GROUP', 'BY')) {
            $reasons[] = 'Query memakai GROUP BY (agregasi).';
        }

        // --- fungsi agregat / analitik ---
        foreach (self::AGGREGATES as $aggregate) {
            if (in_array($aggregate, $words, true)) {
                $reasons[] = "Query memakai fungsi agregat {$aggregate}.";
                break;
            }
        }

        if (in_array('OVER', $words, true)) {
            $reasons[] = 'Query memakai klausa analitik (OVER).';
        }

        if ($scanner->hasSequence('WITHIN', 'GROUP')) {
            $reasons[] = 'Query memakai WITHIN GROUP (agregasi terurut).';
        }

        // --- klausa utama ---
        $selectIndex = $this->findFirstSelect($scanner);
        $fromIndex = $selectIndex === null ? null : $this->findTopLevelFrom($scanner, $selectIndex);

        if ($selectIndex === null || $fromIndex === null) {
            $reasons[] = 'Tidak dapat menemukan klausa SELECT ... FROM.';

            return new QueryShape(false, null, [], array_values(array_unique($reasons)));
        }

        [$columns, $hasAlias, $hasExpression, $listReasons] = $this->parseSelectList($scanner, $selectIndex, $fromIndex);
        $reasons = array_merge($reasons, $listReasons);

        $fromEnd = $this->findFromEnd($scanner, $fromIndex);
        [$tables, $subqueryInFrom] = $this->parseFromClause($scanner, $fromIndex, $fromEnd);

        if ($subqueryInFrom) {
            $reasons[] = 'Klausa FROM berisi subquery.';
        }

        if (count($tables) > 1) {
            $reasons[] = 'Query membaca lebih dari satu tabel: '.implode(', ', $tables).'.';
        }

        $baseTable = (count($tables) === 1 && ! $subqueryInFrom) ? $tables[0] : null;

        $reasons = array_values(array_unique($reasons));

        return new QueryShape(
            isSingleTable: $reasons === [] && $baseTable !== null,
            baseTable: $baseTable,
            selectedColumns: $columns,
            reasons: $reasons,
            referencedTables: $tables,
            hasAlias: $hasAlias,
            hasExpression: $hasExpression,
        );
    }

    private function findFirstSelect(SqlScanner $scanner): ?int
    {
        for ($i = 0; $i < $scanner->count(); $i++) {
            if ($scanner->wordAt($i) === 'SELECT') {
                return $i;
            }
        }

        return null;
    }

    private function findTopLevelFrom(SqlScanner $scanner, int $selectIndex): ?int
    {
        $depth = 0;

        for ($i = $selectIndex; $i < $scanner->count(); $i++) {
            $type = $scanner->typeAt($i);
            $value = $scanner->valueAt($i);

            if ($type === SqlScanner::T_PUNCT) {
                if ($value === '(') {
                    $depth++;
                } elseif ($value === ')') {
                    $depth--;
                }

                continue;
            }

            if ($depth === 0 && $scanner->wordAt($i) === 'FROM') {
                return $i;
            }
        }

        return null;
    }

    private function findFromEnd(SqlScanner $scanner, int $fromIndex): int
    {
        $depth = 0;

        for ($i = $fromIndex + 1; $i < $scanner->count(); $i++) {
            $type = $scanner->typeAt($i);
            $value = $scanner->valueAt($i);

            if ($type === SqlScanner::T_PUNCT) {
                if ($value === '(') {
                    $depth++;
                } elseif ($value === ')') {
                    if ($depth === 0) {
                        return $i;
                    }
                    $depth--;
                }

                continue;
            }

            if ($depth === 0) {
                $word = $scanner->wordAt($i);

                if ($word !== null && in_array($word, self::FROM_TERMINATORS, true)) {
                    return $i;
                }
            }
        }

        return $scanner->count();
    }

    /**
     * @return array{0:array<int,string>,1:bool,2:bool,3:array<int,string>}
     */
    private function parseSelectList(SqlScanner $scanner, int $start, int $end): array
    {
        $columns = [];
        $reasons = [];
        $hasAlias = false;
        $hasExpression = false;
        $star = false;

        $current = [];
        $depth = 0;

        for ($i = $start + 1; $i < $end; $i++) {
            $type = $scanner->typeAt($i);
            $value = $scanner->valueAt($i);

            if ($type === SqlScanner::T_PUNCT) {
                if ($value === '(') {
                    $depth++;
                } elseif ($value === ')') {
                    $depth--;
                } elseif ($value === ',' && $depth === 0) {
                    $this->inspectItem($scanner, $current, $columns, $star, $hasAlias, $hasExpression, $reasons);
                    $current = [];
                    continue;
                }
            }

            $current[] = [$type, $value, $i];
        }

        $this->inspectItem($scanner, $current, $columns, $star, $hasAlias, $hasExpression, $reasons);

        if ($star) {
            $columns = ['*'];
            $hasExpression = false;
        }

        return [array_values($columns), $hasAlias, $hasExpression, array_values(array_unique($reasons))];
    }

    /**
     * @param  array<int,array{0:string,1:string,2:int}>  $item
     * @param  array<int,string>  $columns
     * @param  array<int,string>  $reasons
     */
    private function inspectItem(
        SqlScanner $scanner,
        array $item,
        array &$columns,
        bool &$star,
        bool &$hasAlias,
        bool &$hasExpression,
        array &$reasons,
    ): void {
        if ($item === []) {
            return;
        }

        // SELECT * / t.*
        $first = $item[0];
        if (count($item) === 1 && $first[0] === SqlScanner::T_PUNCT && $first[1] === '*') {
            $star = true;

            return;
        }

        if (count($item) === 3
            && $item[1][1] === '.'
            && $item[2][1] === '*'
            && $item[0][0] === SqlScanner::T_WORD) {
            $star = true;

            return;
        }

        // --- deteksi ALIAS (eksplisit "AS x" atau implisit "expr x") ---
        $count = count($item);

        for ($i = $count - 1; $i >= 1; $i--) {
            [$type, $value] = $item[$i];

            if ($type !== SqlScanner::T_WORD && $type !== SqlScanner::T_QUOTED_IDENT) {
                continue;
            }

            [$prevType, $prevValue] = [$item[$i - 1][0], strtoupper($item[$i - 1][1])];

            $explicit = $prevType === SqlScanner::T_WORD && $prevValue === 'AS';

            $implicit = false;
            if (! $explicit) {
                if ($prevType === SqlScanner::T_NUMBER || $prevType === SqlScanner::T_STRING) {
                    $implicit = true;
                } elseif ($prevType === SqlScanner::T_PUNCT && $prevValue === ')') {
                    $implicit = true;
                } elseif ($prevType === SqlScanner::T_WORD && ! in_array($prevValue, self::NON_ALIAS_KEYWORDS, true)) {
                    $implicit = true;
                }
            }

            if ($explicit || $implicit) {
                $hasAlias = true;
                $reasons[] = 'Select list memakai ALIAS. Nama kolom harus identik dengan Oracle, jadi alias tidak diizinkan.';
                $item = array_slice($item, 0, $i);
                break;
            }
        }

        // Buang kata AS yang tersisa.
        $item = array_values(array_filter(
            $item,
            fn (array $token) => ! ($token[0] === SqlScanner::T_WORD && strtoupper($token[1]) === 'AS'),
        ));

        // --- deteksi EXPRESSI ---
        $hasParenthesis = false;
        $hasKeyword = false;

        foreach ($item as [$type, $value]) {
            if ($type === SqlScanner::T_PUNCT && $value === '(') {
                $hasParenthesis = true;
            }

            if ($type === SqlScanner::T_WORD && in_array(strtoupper($value), self::AGGREGATES, true)) {
                $hasKeyword = true;
            }
        }

        if ($hasParenthesis || $hasKeyword) {
            $hasExpression = true;
            $reasons[] = 'Select list memuat ekspresi atau fungsi, bukan referensi kolom langsung.';
        }

        $name = $this->columnNameFromItem($item);

        if ($name !== null) {
            $columns[] = $name;
        }
    }

    /**
     * @param  array<int,array{0:string,1:string,2:int}>  $words
     */
    private function columnNameFromItem(array $words): ?string
    {
        if ($words === []) {
            return null;
        }

        $identifiers = [];

        foreach ($words as [$type, $value]) {
            if ($type === SqlScanner::T_QUOTED_IDENT) {
                $identifiers[] = $this->unquote($value);
                continue;
            }

            if ($type === SqlScanner::T_WORD) {
                $identifiers[] = $value;
                continue;
            }

            if ($type === SqlScanner::T_PUNCT && $value === '.') {
                continue;
            }

            // Operator, angka, kurung -> bukan kolom sederhana.
            return null;
        }

        if ($identifiers === []) {
            return null;
        }

        return strtoupper((string) end($identifiers));
    }

    /**
     * @return array{0:array<int,string>,1:bool}
     */
    private function parseFromClause(SqlScanner $scanner, int $fromIndex, int $fromEnd): array
    {
        $tables = [];
        $subquery = false;

        $current = [];
        $depth = 0;

        for ($i = $fromIndex + 1; $i < $fromEnd; $i++) {
            $type = $scanner->typeAt($i);
            $value = $scanner->valueAt($i);

            if ($type === SqlScanner::T_PUNCT) {
                if ($value === ',' && $depth === 0) {
                    $table = $this->tableFromFragment($current);

                    if ($table === null) {
                        $subquery = true;
                    } else {
                        $tables[] = $table;
                    }

                    $current = [];
                    continue;
                }

                if ($value === '(') {
                    $depth++;
                } elseif ($value === ')') {
                    $depth--;
                }
            }

            $current[] = [$type, $value];
        }

        $table = $this->tableFromFragment($current);

        if ($table === null) {
            $subquery = true;
        } else {
            $tables[] = $table;
        }

        return [array_values(array_unique($tables)), $subquery];
    }

    /**
     * Menghasilkan "SCHEMA.TABEL" dari fragmen klausa FROM.
     *
     * Alias tabel adalah identifier TERAKHIR yang TIDAK didahului titik, dan
     * hanya boleh ada satu. Dengan begitu:
     *   "APPS.PO_HEADERS_ALL h"  -> APPS.PO_HEADERS_ALL
     *   "PO_HEADERS_ALL h"       -> PO_HEADERS_ALL
     *   "APPS.PO_HEADERS_ALL"    -> APPS.PO_HEADERS_ALL
     *
     * @param  array<int,array{0:string,1:string}>  $fragment
     */
    private function tableFromFragment(array $fragment): ?string
    {
        $identifiers = [];
        $sawParenthesis = false;
        $seenDot = false;

        foreach ($fragment as [$type, $value]) {
            if ($type === SqlScanner::T_PUNCT) {
                if ($value === '(') {
                    $sawParenthesis = true;
                    $seenDot = false;
                    continue;
                }

                if ($value === '.') {
                    $seenDot = true;
                    continue;
                }

                return null; // koma, operator, dll
            }

            if ($type === SqlScanner::T_WORD) {
                $upper = strtoupper($value);

                if (in_array($upper, [
                    'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS',
                    'OUTER', 'NATURAL', 'ON', 'USING', 'LATERAL', 'ONLY',
                ], true)) {
                    return null; // ada join
                }

                $identifiers[] = ['value' => $value, 'dotted' => $seenDot];
                $seenDot = false;
                continue;
            }

            if ($type === SqlScanner::T_QUOTED_IDENT) {
                $identifiers[] = ['value' => $this->unquote($value), 'dotted' => $seenDot];
                $seenDot = false;
                continue;
            }

            return null;
        }

        if ($sawParenthesis || $identifiers === []) {
            return null;
        }

        // Identifier terakhir yang tidak bertitik = alias tabel (bila ada).
        $lastUnDotted = null;

        foreach ($identifiers as $index => $identifier) {
            if ($identifier['dotted'] === false) {
                $lastUnDotted = $index;
            }
        }

        $lastIndex = count($identifiers) - 1;

        if ($lastUnDotted === $lastIndex && count($identifiers) >= 2) {
            array_pop($identifiers);
        }

        $parts = array_map(
            fn (array $identifier) => strtoupper($identifier['value']),
            array_values($identifiers)
        );

        if ($parts === [] || count($parts) > 2) {
            return null;
        }

        return implode('.', $parts);
    }

    private function unquote(string $value): string
    {
        return str_replace('""', '"', trim($value, '"'));
    }
}
