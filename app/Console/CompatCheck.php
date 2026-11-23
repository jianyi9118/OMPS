<?php
namespace Omps\Console;

/**
 * `php omps compat:check` (design §01 coding rules). Token-based scan for code that
 * PHP 7.2 cannot parse or that PHP 8.x removed / changed, plus `php -l` on every file
 * with the current interpreter. Run it on both XAMPP 7.2.3 and XAMPP 8.x.
 */
class CompatCheck
{
    const BANNED_FUNCTIONS = array(
        'each' => 'removed in PHP 8.0',
        'create_function' => 'removed in PHP 8.0',
        'money_format' => 'removed in PHP 8.0',
        '__autoload' => 'removed in PHP 8.0',
        'get_magic_quotes_gpc' => 'removed in PHP 8.0',
        'get_magic_quotes_runtime' => 'removed in PHP 8.0',
        'is_resource' => 'GD/OpenSSL return objects in PHP 8; compare with !== false',
        'str_contains' => 'PHP 8.0+ only',
        'str_starts_with' => 'PHP 8.0+ only',
        'str_ends_with' => 'PHP 8.0+ only',
        'array_is_list' => 'PHP 8.1+ only',
        'get_debug_type' => 'PHP 8.0+ only',
        'fdiv' => 'PHP 8.0+ only',
        'utf8_encode' => 'deprecated in PHP 8.2',
        'utf8_decode' => 'deprecated in PHP 8.2',
        'strftime' => 'deprecated in PHP 8.1',
        'mysql_query' => 'removed long ago; use PDO',
    );

    public static function files()
    {
        $out = array();
        $roots = array('app', 'config', 'database', 'public', 'resources', 'tests', 'scripts');
        foreach ($roots as $r) {
            $dir = OMPS_ROOT . '/' . $r;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (substr($f->getFilename(), -4) === '.php') {
                    $out[] = str_replace('\\', '/', $f->getPathname());
                }
            }
        }
        $out[] = OMPS_ROOT . '/omps';
        sort($out);
        return $out;
    }

    public static function run(array $args)
    {
        $problems = array();
        $files = self::files();
        foreach ($files as $file) {
            foreach (self::scan((string) file_get_contents($file)) as $p) {
                $problems[] = self::rel($file) . ':' . $p[0] . '  ' . $p[1];
            }
        }
        $lint = in_array('--no-lint', $args, true) ? array() : self::lint($files);
        foreach ($lint as $l) {
            $problems[] = $l;
        }
        Console::line('compat:check on PHP ' . PHP_VERSION . ': ' . count($files) . ' files');
        foreach ($problems as $p) {
            Console::line('  ' . $p);
        }
        Console::line($problems ? count($problems) . ' problem(s).' : 'No problems found.');
        return $problems ? 1 : 0;
    }

    private static function rel($file)
    {
        return ltrim(substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', OMPS_ROOT))), '/');
    }

    private static function lint(array $files)
    {
        $out = array();
        $php = PHP_BINARY;
        if ($php === '' || !is_file($php)) {
            return array('cannot run php -l: PHP_BINARY unknown');
        }
        foreach ($files as $f) {
            $res = shell_exec(escapeshellarg($php) . ' -n -l ' . escapeshellarg($f) . ' 2>&1');
            if (!is_string($res) || strpos($res, 'No syntax errors') === false) {
                $out[] = self::rel($f) . '  php -l: ' . trim((string) $res);
            }
        }
        return $out;
    }

    /** @return array list of [line, message] */
    public static function scan($code)
    {
        $found = array();
        $tokens = token_get_all($code);
        $sig = array();
        foreach ($tokens as $t) {
            if (is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            $sig[] = $t;
        }
        $n = count($sig);
        $classDepth = array();
        $depth = 0;
        for ($i = 0; $i < $n; $i++) {
            $t = $sig[$i];
            $prev = $i > 0 ? $sig[$i - 1] : null;
            $next = $i + 1 < $n ? $sig[$i + 1] : null;
            $line = is_array($t) ? $t[2] : self::lineOf($sig, $i);
            if ($t === '{' || (is_array($t) && in_array($t[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) {
                $depth++;
            } elseif ($t === '}') {
                if ($classDepth && end($classDepth) === $depth) {
                    array_pop($classDepth);
                }
                $depth--;
            }
            if (!is_array($t)) {
                // ??=
                if ($t === '=' && is_array($prev) && $prev[0] === T_COALESCE) {
                    $found[] = array(self::lineOf($sig, $i), '??= operator (PHP 7.4+)');
                }
                continue;
            }
            $id = $t[0];
            $text = $t[1];
            if (defined('T_COALESCE_EQUAL') && $id === constant('T_COALESCE_EQUAL')) {
                $found[] = array($line, '??= operator (PHP 7.4+)');
            }
            if (defined('T_NULLSAFE_OBJECT_OPERATOR') && $id === constant('T_NULLSAFE_OBJECT_OPERATOR')) {
                $found[] = array($line, '?-> operator (PHP 8.0+)');
            }
            if (defined('T_ATTRIBUTE') && $id === constant('T_ATTRIBUTE')) {
                // #[...] is a comment on PHP 7; allowed only for ReturnTypeWillChange-style hints
                continue;
            }
            if ($id === T_CLASS || $id === T_TRAIT || $id === T_INTERFACE) {
                if (!(is_array($prev) && $prev[0] === T_DOUBLE_COLON)) {
                    $classDepth[] = $depth + 1;
                }
            }
            if ($id === T_STRING || (defined('T_FN') && $id === constant('T_FN')) || (defined('T_MATCH') && $id === constant('T_MATCH'))) {
                $lower = strtolower($text);
                $isCall = $next === '(';
                $isMethodOrConst = is_array($prev) && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST), true);
                if ($isCall && !$isMethodOrConst && array_key_exists($lower, self::BANNED_FUNCTIONS)) {
                    $found[] = array($line, $text . '(): ' . self::BANNED_FUNCTIONS[$lower]);
                }
                if ($lower === 'fn' && $isCall && !$isMethodOrConst) {
                    $found[] = array($line, 'arrow function fn() (PHP 7.4+)');
                }
                if ($lower === 'match' && $isCall && !$isMethodOrConst) {
                    $found[] = array($line, 'match expression (PHP 8.0+)');
                }
                // method names may be keywords; global functions and class names may not
                if (($lower === 'match' || $lower === 'mixed' || $lower === 'enum') && is_array($prev) && (($prev[0] === T_FUNCTION && !$classDepth) || $prev[0] === T_CLASS)) {
                    $found[] = array($line, '"' . $text . '" is reserved in PHP 8');
                }
            }
            // $str{0} string offset with braces (removed in 8.0)
            if ($id === T_VARIABLE && $next === '{' && !(is_array($prev) && $prev[0] === T_OBJECT_OPERATOR)) {
                $found[] = array($line, '$var{...} string offset (removed in PHP 8.0); use $var[...]');
            }
            // typed properties: visibility [static] Type $var inside a class body
            if (in_array($id, array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR), true) && $classDepth && end($classDepth) === $depth) {
                $j = $i + 1;
                if (isset($sig[$j]) && is_array($sig[$j]) && $sig[$j][0] === T_STATIC) {
                    $j++;
                }
                $tt = isset($sig[$j]) ? $sig[$j] : null;
                if ($tt === '?' || (is_array($tt) && in_array($tt[0], array(T_STRING, T_ARRAY, T_NS_SEPARATOR), true))) {
                    $after = isset($sig[$j + 1]) ? $sig[$j + 1] : null;
                    if ($tt === '?' || (is_array($after) && $after[0] === T_VARIABLE)) {
                        $found[] = array($line, 'typed property (PHP 7.4+)');
                    }
                }
            }
            // implicitly nullable parameter "Type $x = null" (deprecated in PHP 8.4; write "?Type $x = null")
            if ($id === T_VARIABLE && $next === '=' && isset($sig[$i + 2]) && is_array($sig[$i + 2]) && strtolower($sig[$i + 2][1]) === 'null') {
                $tt = $prev;
                if ((is_array($tt) && in_array($tt[0], array(T_STRING, T_ARRAY, T_CALLABLE), true)) && !(isset($sig[$i - 2]) && $sig[$i - 2] === '?')) {
                    $found[] = array($line, 'implicitly nullable parameter (deprecated in PHP 8.4); use ?Type');
                }
            }
            // loose comparisons
            if ($id === T_IS_EQUAL || $id === T_IS_NOT_EQUAL) {
                $found[] = array($line, $text . ' loose comparison; use === or !==');
            }
        }
        return $found;
    }

    private static function lineOf(array $sig, $i)
    {
        for ($k = $i; $k >= 0; $k--) {
            if (is_array($sig[$k])) {
                return $sig[$k][2] + substr_count($sig[$k][1], "\n");
            }
        }
        return 0;
    }
}
