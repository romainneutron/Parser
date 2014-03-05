<?php

/*
 * (c) Nicolas Grekas <p@tchwork.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tchwork\Parser\Lexer;

use Tchwork\Parser\Exception\Error;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class PhpLexer implements LexerInterface
{
    private static $asemantics = array(
        T_OPEN_TAG,
        T_WHITESPACE,
        T_COMMENT,
        T_DOC_COMMENT,
        T_BAD_CHARACTER,
    );

    /**
     * Map lexer tokens to parser's ones.
     */
    public function getMaps($yymap, $yytoken, $YYBADCH)
    {
        $yymap = array_flip($yymap);
        $map = array(0 => 0);
        $token = array(0 => $yytoken[0]);

        foreach ($yytoken as $yyid => $yyname) {
            if ($yyid && 'error' !== $yyname) {
                if ($yymap[$yyid] > 256) {
                    if (!defined($yyname)) {
                        continue;
                    }
                    $id = constant($yyname); // T_* token
                } else {
                    $id = chr($yymap[$yyid]);
                }

                $token[$id] = $yyname;
                $map[$id] = $yyid;
            }
        }

        foreach (self::$asemantics as $id) {
            $token[$id] = token_name($id);
            $map[$id] = $YYBADCH;
        }

        $token[T_OPEN_TAG_WITH_ECHO] = 'T_OPEN_TAG_WITH_ECHO';
        $token[T_CLOSE_TAG] = 'T_CLOSE_TAG';
        $map[T_OPEN_TAG_WITH_ECHO] = $map[T_ECHO];
        $map[T_CLOSE_TAG] = $map[';'];

        return array($map, $token);
    }

    /**
     * Return the tokens for the given code.
     *
     * @returns array|Traversable
     */
    public function getTokens($code)
    {
        set_error_handler('var_dump', 0);
        $e = error_reporting(0);
        user_error(''); // reset error_get_last()

        $tokens = token_get_all($code);

        // Seek to the last token that has a line number
        $i = count($tokens);
        while (!isset($tokens[--$i][2])) {}
        $endLine = $tokens[$i][2] + substr_count($tokens[$i][1], "\n");

        // Work around https://bugs.php.net/54089
        $i = error_get_last();
        if (!empty($i['message'])) {
            $tokens = $this->restoreBadCharacters($code, $tokens);
        }
        if (PHP_VERSION_ID < 50400) {
            $this->restoreHaltCompilerData($code, $tokens, $endLine);
        } elseif (PHP_VERSION_ID < 50306 && 0 < stripos($code, '__halt_compiler')) {
            $this->inlineHaltCompilerData($tokens, $endLine);
        }

        error_reporting($e);
        restore_error_handler();

        $i = count($tokens);
        $token = array();
        $tokens[] = array(0, '', $endLine); // END token

        // Put end line instead of start line in every $tokens[$i][2]
        for (; $i >= 0; --$i) {
            if (isset($tokens[$i][2])) {
                $line = $tokens[$i][2];
                $tokens[$i][2] = $endLine;
                $endLine = $line;
            } else {
                $token[2] = $endLine;
                if ('b"' === $token[0] = $token[1] = $tokens[$i]) {
                    $token[0] = '"';
                }
                $tokens[$i] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Re-insert characters removed by token_get_all() as T_BAD_CHARACTER tokens
     */
    protected function restoreBadCharacters($code, $tokens)
    {
        $tmp = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '\\', $code, -1, $i);

        if ($i) {
            $tmp = token_get_all($tmp);

            for ($i = 0, $j = 0, $k = 0; isset($tmp[$j]); ++$i, ++$j, ++$k) {
                if (T_NS_SEPARATOR !== $tmp[$j][0]) {
                    $tmp[$j] = $tokens[$i];
                    if (isset($tokens[$i][1])) {
                        $k += isset($tokens[$i][2]) ? strlen($tokens[$i][1])-1 : 1;
                    }
                } elseif ('\\' !== $code[$k]) {
                    $tmp[$j][0] = T_BAD_CHARACTER;
                    $tmp[$j][1] = $code[$k];
                    --$i;
                }
            }

            return $tmp;
        }

        return $tokens;
    }

    /**
     * Restore data after __halt_compiler
     *
     * Work around missed fix to https://bugs.php.net/54089
     */
    protected function restoreHaltCompilerData($code, &$tokens, &$endLine)
    {
        $i = end($tokens);

        if (T_HALT_COMPILER !== $i[0]) {
            return;
        }

        $i = key($tokens);

        for ($j = 0, $k = 0; $j <= $i; ++$j, ++$k) {
            if (isset($tokens[$j][1])) {
                $k += isset($tokens[$j][2]) ? strlen($tokens[$j][1])-1 : 1;
            }
        }

        if (isset($code[$k])) {
            $tmp = '<?php '.str_repeat("\n", $endLine-1).' @'.substr($code, $k);
            $tmp = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '\\', $tmp);
            $tmp = token_get_all($tmp);

            for ($i = 0, $j = 3; $i < 3 && isset($tmp[$j]); ++$i, ++$j, ++$k) {
                if (T_NS_SEPARATOR !== $tmp[$j][0] || '\\' === $code[$k]) {
                    if (isset($tmp[$j][2])) {
                        $endLine = $tmp[$j][2] + substr_count($tmp[$j][1], "\n");
                        $k += strlen($tmp[$j][1]) - 1;
                        if (in_array($tmp[$j][0], self::$asemantics)) {
                            --$i;
                        }
                    } elseif ('b"' === $tmp[$j]) {
                        ++$k;
                    }
                } else {
                    $tmp[$j][0] = T_BAD_CHARACTER;
                    $tmp[$j][1] = $code[$k];
                }

                $tokens[] = $tmp[$j];
            }
        }

        if (isset($code[$k])) {
            $tmp = substr($code, $k);
            $tokens[] = array(T_INLINE_HTML, $tmp, $endLine);
            $endLine += substr_count($tmp, "\n");
        }
    }

    /**
     * Work around https://bugs.php.net/54089
     */
    protected function inlineHaltCompilerData(&$tokens, &$endLine)
    {
        for ($i = 1; isset($tokens[$i]); ++$i) {
            if (T_HALT_COMPILER === $tokens[$i][0]) {
                for (++$i, $j = 0; $j < 3 && isset($tokens[$i]); ++$i, ++$j) {
                    if (isset($tokens[$i][2])) {
                        $endLine = $tokens[$i][2] + substr_count($tokens[$i][1], "\n");
                        if (in_array($tokens[$i][0], self::$asemantics)) {
                            --$j;
                        }
                    }
                }

                if (isset($tokens[$i])) {
                    $j = $i;
                    $data = '';

                    do {
                        if (isset($tokens[$j][2])) {
                            $data .= $tokens[$j][1];
                        } else {
                            $data .= $tokens[$j];
                        }
                    } while(isset($tokens[++$j]));

                    array_splice($tokens, $i, $j, array(array(T_INLINE_HTML, $data, $endLine)));
                    $endLine += substr_count($data, "\n");
                }

                break;
            }
        }
    }
}
