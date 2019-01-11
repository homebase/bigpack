<?php

namespace hb\util;

/**
 * Generic classes / methods
 */


/**
 * Create Cli Tool from php Class
 *
 * Provides:
 *   Generic Help - phpdoc for Your_Class
 *   Specific methods help via: $script help MethodName
 *
 * Usage:
 *   extend this class, then
 *   $MYCLASSNAME::_run($argv);
 *
 */
class CliTool
{

    /**
     * run method specified as first argument or show help
     * read params from "$class.options" or "$class/options" file, add them to args passed as cli options
     */
    static function _run($argv)
    {
        $args = Util::args($argv);
        #if (@$args['vv'])  // -vv = very-verbose
        #    echo json_encode(['options' => $args]), "\n";
        $command = $args[1] ?? "help";
        if ($command[0] === '_')
            die("can't run internal command");
        $class = get_called_class();
        $run = $class . "::$command";
        // read options
        //foreach ([$class.".options", $class."/options"] as $fn) {
        //    if (file_exists($fn))
        //        $args += parse_ini_file($fn); // cli options override options
        //}
        $run($args);
    }

    /**
     * show help from phpdoc
     * show default help, list all methods along with first line of help
     */
    static function help($args)
    {
        $what = $args[2] ?? "help";
        $class = get_called_class();
        $run = "$class::$what";
        echo "$run\n\n";
        echo $what === 'help' ? Util::methodDoc($class) : Util::methodDoc($run);
        if ($what === "help") { # Show all methods along with
            $methods = (new \ReflectionClass($class))->getMethods();
            echo "\n\nMethods:\n";
            foreach ($methods as $m) {
                if ($m->isPrivate() || $m->isProtected())
                    continue;
                if ($m->class != __class__ && $m->name[0] !== '_' && $m->name !== 'help') { // non-internal method from new class
                    $method_doc = Util::methodDoc($m->class . "::" . $m->name);
                    $first_line = strstr($method_doc, "\n", true) ? : $method_doc;
                    echo "* " . $m->name . "\n    " . $first_line . "\n";
                }
            }
        }
        echo "\n";
        exit;
    }

    /**
     * unknown command case
     */
    public static function __callStatic($method, $args)
    {
        Util::error("unknown command $method");
    }

}

/**
 * Misc generic methods
 */
class Util
{

    /**
     * show error to STDERR, terminate with ErrorCode
     */
    static function error($message, int $code = 1)
    {
        fprintf(STDERR, $message . "\n");
        exit($code);
    }

    // Parse command line arguments into options and arguments (~ http://tldp.org/LDP/abs/html/standard-options.html)
    //
    // ./php-script -abc --d --c="VALUE" test1 test2
    // -ab           is ['a' => true, 'b' -> true]
    // --ab          is ['ab' => true]
    // --ab="value"  is ['ab' => value]
    // --ab=value    is ['ab' => value]
    // --            is READ argument-list from STDIN
    // @return : ['option1' => ., 'option2' => ., ..., 0 => $argv[0], 1 => $argument1, ... ] ]
    static function args(array $argv) : array
    { # ['option1' => ., 'option2' => ., ..., 0 => $argv[0], 1 => $argument1, ... ] ]
        $options = [];
        $args = [0 => $argv[0]];
        array_shift($argv);
        foreach ($argv as $a) {
            if ($a {
                0} !== '-') {
                $args[] = $a;
                continue;
            }
            error_if(strlen($a) < 2, "incorrect arg: $a");
            // -abc
            if ($a {
                1} !== '-') { // -ab == ['a' => true, 'b' -> true]
                error_if(strpos($a, "="), "incorrect argument: $a\nuse --name=value instead");
                foreach (range(1, strlen($a) - 1) as $p)
                    $options[$a[$p]] = true;
                continue;
            }
            // "--"
            if ($a == "--") {
                while ($line = fgets(STDIN))
                    $args[] = trim($line);
                continue;
            }
            $k = substr($a, 2); // cut leading "--"
            // --name
            // --name="value"
            // --name=value
            $v = true;
            if (strpos($k, "=")) {
                [$k, $v] = explode("=", $k);
                $v = trim($v, '"');
            }
            $options[$k] = $v;
        }
        $options = $args + $options; // just mix them together. options[0] = $argv[0]
        return $options;
    }

    /**
     * get method's or class php-doc
     */
    static function methodDoc(string $classMethod) : string
    { # method's php-doc
        if (strpos($classMethod, '::')) {
            [$class, $method] = explode("::", $classMethod);
            $rc = new \ReflectionClass($class);
            $doc = $rc->getMethod($method)->GetDocComment() ?? "";
        } else {
            $rc = new \ReflectionClass($classMethod);
            $doc = $rc->GetDocComment() ?? "";
        }
        $doc = trim($doc, "/* \n");
        $doc = preg_replace("!^\ +\*\ ?!m", "", $doc);
        return $doc;
    }

    /**
     * open file for appending, with exclusive lock
     * fail otherwise
     */
    static function openLock(string $filename, string $mode = "a+b", int $timeout_secs = 10)
    { # filehandler
        $fh = fopen($filename, $mode);
        $start = time();
        while (!flock($fh, LOCK_EX | LOCK_NB, $wouldblock)) {
            if ($wouldblock && time() - $start < $timeout_secs) {
                fprintf(STDERR, "Waiting for lock on file: $filename");
                usleep(200000); // 0.2s
            } else {
                self::error("Can't acquire lock on file: $filename within $timeout_secs seconds");
            }
        }
        return $fh;
    }

    /**
     * Generator - scan files in given directory and subdirectories
     * skips hidden files ".*"
     * returns relative path
     *
     * @param  $rdir is internal paramether - always pass ""
     * @param  $fileCallback($dir, $filename)         - return true to skip file
     * @param  $dirEntryCallback($base_dir, $dirName) - return true to skip directory
     */
    static function fileScanner(
                        string $base_dir,
                        /* private */ string $rdir = "",
                        ? callable $fileCallback = null,
                        ? callable $dirEntryCallback = null
    ) 
    { # Generator >> Path/File
        $dir = $base_dir . ($rdir ? "/$rdir" : "");
        foreach (scandir($dir) as $file) {
            if ($file {0} === ".") // no cur-dir / hidden files / hidden directories
                continue;
            if ($fileCallback && $fileCallback($dir, $file))
                continue;
            if (is_dir("$dir/$file")) {
                if ($dirEntryCallback && $dirEntryCallback($dir, $file))
                    continue;
                yield from self::fileScanner($base_dir, $rdir ? "$rdir/$file" : $file, $fileCallback, $dirEntryCallback);
                continue;
            }
            yield $rdir ? "$rdir/$file" : $file;
        }
    }

    /**
     * Generator - read lines from gzipped files
     */
    static function gzLineReader(string $filename)
    {
        $zh = gzopen($filename, 'r') or Util::error("can't open: $filename");
        $cnt = 0;
        while ($line = gzgets($zh, 1024)) {
            yield rtrim($line);
        }
        gzclose($zh) or Util::error("can't close: $php_errormsg");
    }

    /**
     * return last lines of a file in reverse order (last line goes first)
     * number of lines depends on $buffer size
     */
    static function fileLastLines(string $filename, int $buffer = 8192) : array
    { 
        $fh = fopen($filename, 'r') or Util::error("can't open: $filename");
        fseek($fh, -$buffer , SEEK_END);
        $lines = explode("\n", fread($fh, $buffer));
        array_pop($lines); // last line is always empty
        array_shift($lines); // throw out partially-read line
        fclose($fh);
        return array_reverse($lines);
    }

} // class Util

/**
 * non recoverable Error -  developer uses Code Incorrect Way
 * throw \hb\Error exception if ...
 */
function error_if($boolean, string $message)
{
    if ($boolean)
        throw new \Error($message);  // \Error descendant
}
