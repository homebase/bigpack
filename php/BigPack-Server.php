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
 *
 * TODO:
 *   Keep Data File Opened
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

    const VERSION = "1.0.1";

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
            fwrite(STDERR, "Options: ".json_encode($this->opts). "\n");
        } else {
            fwrite(STDERR, "No options file found\n");
        }
        $this->init();
    }

    function init() {
        fprintf(STDERR, "%s", "INIT. Bigpack-Web-Server Version: ".Server::VERSION."\n");
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
        $ext_pos = strrpos($file, '.', 1);
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

    function serve(string $uri) {
        // @$this->stats['requests']++;
        // fwrite(STDERR, json_encode([$this->stats, $this->opts])."\n");
        $file = substr($uri, 1) ?: "index.html";
        if ($file{-1} === '/')
            $file .= "index.html";
        $fh = Core::hash($file);
        $offset = $this->_offset($fh);
        if ($offset === 0) {
            header("HTTP/1.0 404 Not Found");
            echo "<h1>Error 404 - File <u>$file</u> Not Found</h1>";
            return;
        }
        if ($offset === 1) {
            header("HTTP/1.0 500 Not Found");
            echo "<h1>Error 500 - Source files out of sync</h1>";
            return;
        }
        [$data, $dh, $gzip] = Core::_readOffset((int) $offset, 1);
        $etag = bin2hex($dh);
        if ($query_etag = @$_SERVER['HTTP_IF_NONE_MATCH']) {
            if ($query_etag === $etag) {
                header("HTTP/1.1 304 Not Modified");
                return;
            }
        }
        if ($gzip)
            header("Content-Encoding: deflate"); // serve compressed data
        if (! $data && ! $dh) {
            header("HTTP/1.1 410 Gone");
            return;
        }
        header("Content-Type: ".$this->mimeType($file, $data, $gzip));
        header("Etag: $etag");
        if ($this->expires_min)
            header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + ($this->expires_min * 60)));
        echo $data;
    }


}