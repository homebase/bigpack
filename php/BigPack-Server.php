<?php

/**
 *
 * Sample Bigpack Server
 * Required Module APCU
 *
 * BigPack.options file:
 *     mime-types=/etc/mime.types   << DEFAULT (use same format)
 *     expires-minutes=50            << DEFAULT 0
 *
 *   [+] HTTP-ETAG tag  == ContentHash support
 *   [+] HTTP-Expires Tag
 *   [+] HTTP 410 GONE for deleted files
 *   [+] MAP2/MAP out of sync (new MAP file upload)
 *       re-read MAP2 file, try again, if still out-of-sync - return 500
 *   [+] WEB-API [+] delete method
 *
 *
 * BigPack WebApi:
 * * located at http://$BIGPACK_WEB_SERVER/bigpack-api/$method?...&pass=$passkey
 * * specify $passkey in BigPack.options "web-api-password" field
 *
 * BigPack WebApi Methods:
 * * delete :  wrapper around "bigpack deleteContent" (add "&undelete=1" to undelete file)
 *             make sure process have write permission
 *
 * TODO:
 *   Avoid whole-file reads when ETAG matches
 *
 */


// HB = homebase framework namespace
namespace hb\bigpack;

use hb\util\CliTool;
use hb\util\Util;

/**
 * Sample BigPack Server
 * Caching Wrapper
 */
class Server {

    const VERSION = "1.0.3";

    // shared memory key
    // SHM Data shared only between php-fpm / parent-php-process-children
    static $MAP2_SHM_KEY = "Bigpack.MAP2";
    // $_SERVER['REQUEST_URI'])
    static function serve($uri) {
        if(!defined('STDERR'))
            define('STDERR', fopen('php://stderr', 'w'));
        if (!function_exists("apcu_fetch"))
            Util::error("install APCU - http://php.net/manual/en/intro.apcu.php");
        $S = apcu_fetch(self::$MAP2_SHM_KEY);
        if (! $S) {
            $S = new ExtractorWeb([]);
            apcu_store(self::$MAP2_SHM_KEY, $S);
        }
        $S->serve($uri);
    }

}

/**
 * Actual BigPack Web Service
 */
class ExtractorWeb extends ExtractorMap2 {

    var $mime_types;  // EXT => mime type
    var $expires_min = 0; // Expires Header - minutes

    function __construct(array $opts) {
        $this->opts = $opts;
        if (file_exists(Core::OPTIONS)) {
            $this->opts += parse_ini_file(Core::OPTIONS);
        }
        $this->opts['debug'] = (int) ($this->opts['debug'] ?? "0");
        fwrite(STDERR, "Options: ".json_encode($this->opts). "\n");
        $this->init();
    }

    function init() {
        fprintf(STDERR, "%s", "INIT. Bigpack-Web-Server Version: ".Server::VERSION." path=".getcwd()."\n");
        parent::init();
        $this->mimeTypeMapInit();
        $this->expires_min = @$this->opts['expires-minutes'] ?? 0;
    }

    /**
     * read file "--mime-types=$filename" or use /etc/mime.types
     * @return [type] [description]
     */
    protected function mimeTypeMapInit() {
        $file = $this->opts['mime-types'] ?? "/etc/mime.types";
        $source = file($file);
        if (! $source)
        Util::error("File $file Required");
        foreach ($source as $line) {
            if ($line{0} === '#')
            continue;
            $got = preg_match("/^(.*?)\s+(.*?)$/", $line, $m);
            if (! $got || ! $m[2]) {
                continue;
            }
            foreach (explode(" ", $m[2]) as $ext)
            $this->mime_types[$ext] = $m[1];
        }
        # echo "<pre>".json_encode($this->mime_types, JSON_PRETTY_PRINT)."</pre>";
    }

    /**
     * find out mime for an file
     */
    protected function mimeType(string $file, string $content, $gzip) {
        $ext = "";  // will not unzip
        $filename_start = strrpos($file, '/', 1) ?: 1;
        $ext_pos = strrpos($file, '.', $filename_start);
        if ($ext_pos === false) { // NO Extension - analyze file
            if ($gzip) {
                if (strlen($data) > 65536)
                    return $this->mime_types["bin"]; // do not de-compress big files
                $content = gzinflate($content);
            }
            $mime = @getimagesizefromstring($content)['mime'];
            if ($mime)
                return $mime;
            if ($content[0] === '<')
                $ext = "html";
        } else {
            $ext = substr($file, $ext_pos +1) ?? "bin";
        }
        return $this->mime_types[$ext] ?? "application/octet-stream";
    }

    /**
     * Config Option "debug"
     *   debug & 1 : show filename in 404 message
     *   debug & 2 : show 404, 410 (file not found, file gone)
     *   debug & 4 : show 200 (HTTP_OK)
     *   debug & 8 : show 304 (not modified)
     */
    function serve(string $uri) {
        // @$this->stats['requests']++;
        // fwrite(STDERR, json_encode([$this->stats, $this->opts])."\n");
        if (! strncmp($uri, "/bigpack-api/", 13)) {  // BigPack API
            // /bigpack-api/delete?file=sds&pass=parf123456789   << password in "pass" parateter
            preg_match("!^([a-z]+)\?!", substr($uri, 13), $m);
            $method = $m[1] ?? "";
            (new WebAPI($this->opts['web-api-password'] ?? ""))->_exec($method);
            return;
        }
        $file = substr($uri, 1) ?: "index.html";
        if ($file{-1} === '/')
            $file .= "index.html";
        $fh = Core::hash($file);
        $offset = $this->_offset($fh);
        if ($offset === 0) {
            header("HTTP/1.0 404 Not Found");
            if (@$this->opts['debug'])
                echo "<h1>Error 404 - File <u>$file</u> Not Found</h1>";
            else
                echo "<h1>File Not Found</h1>";
            $this->log(2, "404\t$file");
            return;
        }
        if ($offset === 1) {
            header("HTTP/1.0 500 Not Found - file out of sync");
            fwrite(STDERR, "source file out of sync. serving\t$file\n");
            echo "<h1>Error 500 - Source files out of sync</h1>";
            return;
        }
        [$data, $dh, $gzip] = Core::_readOffset((int) $offset, 1);
        if (! $data && ! $dh) {
            $this->log(2, "410\t$file");
            header("HTTP/1.1 410 Gone");
            return;
        }
        $etag = bin2hex($dh);
        if ($query_etag = @$_SERVER['HTTP_IF_NONE_MATCH']) {
            if ($query_etag === $etag) {
                $this->log(8, "304\t$file");
                header("HTTP/1.1 304 Not Modified");
                return;
            }
        }
        if ($gzip)
            header("Content-Encoding: deflate"); // serve compressed data
        header("Content-Type: ".$this->mimeType($file, $data, $gzip));
        header("Etag: $etag");
        if ($this->expires_min)
            header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + ($this->expires_min * 60)));
        $this->log(4, "200\t$file");
        echo $data;
    }

    /**
     * show debug message to STDERR (console)
     * format: date time\tmessage\t$IP
     */
    function log($debugBitMask, $message) {
        //($this->opts['debug'] ?? 0) & $debugBitMask && fwrite(STDERR, date("y-m-d H:i:s")."\t".$message."\t".$_SERVER["REMOTE_ADDR"]."\n");
        // ip is useless - it is always IP of proxy
        $this->opts['debug'] & $debugBitMask && fwrite(STDERR, date("y-m-d H:i:s")."\t".$message."\n");
    }

}

/**
  * Bigpack Web API
  */
class WebAPI {

    private /* string */ $pass;

    function __construct(string $pass) {
        $this->pass = $pass;
    }

    function _exec($method) {
        // security
        if (strlen($this->pass) < 10) {
            fwrite(STDERR, "web-api-password - misconfiguration - no password or password is too short\n");
            header("HTTP/1.0 500 web-api misconfiguration");
            return;
        }
        // ACCESS
        if ($this->pass !== $_GET['pass']) {
            header("HTTP/1.0 403 access denied");
            fwrite(STDERR, "web-api password incorrect\n");
            return;
        }
        if ($method{0} === '_') {
            header("HTTP/1.0 400 GTFO");
            fwrite(STDERR, "web-api internal method call: '$method'\n");
            return;
        }
        if (! method_exists($this, $method)) {
            header("HTTP/1.0 501 Not implemented");
            fwrite(STDERR, "method $method not implemented\n");
            return;
        }
        // api method should return (string)"OK" or (array) or (srting) $error
        $r = $this->$method($_GET);
        if (! is_array($r) && $r !== "OK") {
            header("HTTP/1.0 500 method $method error");
            echo $r;
            fwrite(STDERR, "web-api error: $method call\n");
            return;
        }
        header("Content-Type: application/json");
        echo json_encode($r);
    }

    /**
     * mark file as deleted (or undelete it)
     */
    function delete(array $opts) {
        $file = $opts['file'] ?? 0;
        if (! $file)
            return "'file' parameter expected";
        $_opts = [];
        if (@$opts['undelete'])
            $_opts['undelete'] = 1;
        (new Packer($_opts))->_deleteContent($file);
        // echo "File : $file";
        return "OK";
    }

}