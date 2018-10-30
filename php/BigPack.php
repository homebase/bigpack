<?php

/**
 *
 * TODO:
 *   1. HTTP-ETAG tag  == ContentHash support
 *   2. HTTP-Expires Tag
 *   3. File Deletion (flag - file - deleted - serve HTTP-GONE(410 CODE))
 *   4. Parallel Adding of files (buffered save)
 *   5. Service - adding files in runtime - buffer + rebuild
 *      new-files buffer, then merge-sort + index rebuild
 *   6. Using 2**BITS hash prefix index instead of map2
 *
 * BigPackAdder Class - diff cases for "init, add, update"
 *
 * Global Options:
 *     dir  : dir where to take source files
 *     v    : verbose
 *     vv   : very-verbose (show debug info)
 *
 * Packer options:
 *     no-gzip : space delimited list of file extensions not to even-try to compress. use "--vv" to see default list
 *     rm      : remove archived files
 *
 * testing:
 *   ./bigpack init --rewrite --vv -v
 *   ./bigpack add  --vv -v
 *
 *
 * @todo :
 *    store directories (along with permissions)
 *    remove directories (when --rm)
 *       save directory list, try to remove non-empty dirs after
 *
 *    "diff --hard" - iterate over known files. compare Core::hash(content)
 *
 * ISSUES: directories - creation / removal / storing
 *
 */


namespace hb\bigpack;

use hb\util\CliTool;
use hb\util\Util;

include __DIR__."/Util.php";     // \hb\util - generic classes

/**
 * Actual BigPack Class
 */
class Core {

    // bitmap flags
    CONST FLAG_GZIP    = 1;
    CONST FLAG_DELETED = 2;

    CONST DATA_PREFIX = 16; // bytes

    // filenames
    CONST INDEX = 'BigPack.index';
    CONST DATA  = 'BigPack.data';
    CONST MAP   = 'BigPack.map';
    CONST MAP2  = 'BigPack.map2';
    CONST MAPH  = 'BigPack.maph'; // map hash. top-16bit of filenamehash => map-item-nn

    CONST VERSION = '0.2.0'; // semver

    /**
     * Generator
     *   return items in form:
     *      [0 => "#FileName", 1 => "FilenameHash",  2 => "DataHash", 3 => "FilePerms", 4 => "FileMTime", 5 => "AddedTime", 6 => "DataOffset"]
     */
    static function indexReader() {
        $fh_index = fopen(Core::INDEX, "r");
        while (($L = stream_get_line($fh_index, 1024 * 1024, "\n")) !== false) {
            if ($L{0} === '#')
                continue;
            $d = explode("\t", $L);
            $d[1] = hex2bin($d[1]);
            $d[2] = hex2bin($d[2]);
            $d[3] = (int) base_convert($d[3], 8, 10);
            yield $d;
        }
        fclose($fh_index);
    }

    // stateless function must be declared as static
    static function hash(string $data) : string { # 10 byte hash
        $md5 = hash("md5", $data, 1);
        return substr($md5, 0, 10);
    }

    /**
     * Read data from Data file at specified offset
     *
     * - decompress if compressed
     * - support is-deleted
     * @see Packer::_write for a writer
     */
    static function _readOffset(int $offset) : array { # [data, data-hash]
        static $READ_BUFFER = 1024 * 16; // 16K
        // read 16K
        $fh = fopen(Core::DATA, "rb");
        // DATA IS: Prefix + $data
        //    pack("LA10c", $len, $data_hash, $flags).$data;
        // PREFIX IS:
        //    uint32 size, byte size_high_byte, byte[10] data-hash, byte flags, byte[$len] data  // 16 byte prefix
        fseek($fh, $offset, SEEK_SET);
        $data = fread($fh, $READ_BUFFER);
        $d = unpack("Lsize/chsize/a10dh/cflag", $data); // LOWERCASE "a", uppercase corrupt data
        // var_dump([$offset, $d]);
        if ($d['size'] <= $READ_BUFFER - Core::DATA_PREFIX) { // prefix size
            $data = substr($data, Core::DATA_PREFIX, $d['size']);
        } else {
            $data = substr($data, Core::DATA_PREFIX);
            $d['size'] += $d['hsize'] << 32; // High Byte #5
            $remaining = $d['size'] - (Core::DATA_PREFIX - $READ_BUFFER);
            $data = $data.fread($fh, $remaining);
        }
        if ($d['flag'] & Core::FLAG_DELETED)
            return ["", ""]; // File Deleted
        if ($d['flag'] & Core::FLAG_GZIP)
            $data = gzinflate($data);
        fclose($fh);
        return [$data, $d['dh']];
    }

    /**
     * create directories structure (ala mkdir -p) needed to extract file $file
     * keeps track of existing directories
     */
    static function _makeDirs(string $file) {
        static $dir_exists = []; // dir => 1
        $path = substr($file, 0, strrpos($file, '/'));
        if (@$dir_exists[$path])
            return;
        if (is_dir($path)) {
            $dir_exists[$path] = 1;
            return;
        }
        # @$this->opts['vv'] && print("creating directory: $path\n");  // debug
        mkdir($path, 0775, true);
        #die("$file => $path");
    }

}

class Packer {

    static $WRITE_BUFFER_FILES   = 1000;        // NN files to keep in write buffer
    static $WRITE_BUFFER_SIZE    = 10 << 20;    // size in MB
    static $no_gzip_default = "gz bz2 tgz xz jpg jpeg gif png webp zip 7z rar";

    // public
    var $opts = []; // options from BigPack.options and Cli
    var $now;       // transaction-time

    var $dir = "."; // data-source directory. "--dir" or current directory
    var $stat = []; // statistics

    var $DATAHASH_OFFSET = []; // data_hash => offset
    var $KNOWN_FILE = [];  // hash(file) => { 1 || last-modified-time }


    // file handles
    private  $fh_index;
    private  $fh_data;
    private  $no_gzip; //

    function __construct(array $opts) {
        $this->opts = $opts;
        $this->now = time();
        $this->dir = $this->opts["dir"] ?? "."; // "--dir=xxx" or current dir
        $no_gzip = $opts['no-gzip'] ?? self::$no_gzip_default; // space delimited list of extensions
        $this->no_gzip = array_flip(explode(" ", ' '.$no_gzip)); // ext => 1
        if (@$args['vv'])  // -vv = very-verbose
            echo json_encode(['options' => $args, 'no-gzip' => $this->no_gzip]), "\n";
    }



    /**
     * Generator - scan files in directory
     * returns filename and its hash
     */
    function fileScanner() { # Generator that yields [fileName, filenameHash]
        // skip BigData Archive Files
        $fileCallback = function($dir, $file) {
            if ($file === Core::INDEX || $file === Core::DATA || $file === Core::MAP || $file === Core::MAP2)
                return 1;
        };
        // skip directories with BigData Archives
        $dirEntryCallback = function($dir, $file) {
            if (file_exists("$dir/$file/".Core::INDEX))
                return 1;
        };
        foreach (Util::fileScanner($this->dir, "", $fileCallback, $dirEntryCallback) as $fp) {
            yield [$fp, Core::hash($fp)];
        }
    }


    /**
     * Generator - scan NEW (unknown) files in directory
     * returns filename and its hash
     */
    function newFileScanner($dir = null) { # Generator that yields fileName
        foreach ($this->fileScanner($dir) as [$file, $filename_hash]) {
            if (@$this->KNOWN_FILE[$filename_hash]) {
                @$this->stat['known-files']++;
                continue;
            }
            $this->KNOWN_FILE[$filename_hash] = 1;
            yield [$file, $filename_hash];
        }
    }

    /**
     * Update existing Archive, Add NEW Files
     */
    function add() : int { # NN files-added
        if (! file_exists(Core::INDEX)) {
            Util::error("no BigPack archive found - refusing to run");
        }
        // load from index: [filehash => 1] into $this->FILES;
        $fh_index = fopen(Core::INDEX, "r");
        while (($L = stream_get_line($fh_index, 1024 * 1024, "\n")) !== false) {
            // [0 => "#FileName", "FilenameHash", "DataHash", "FilePerms", "FileMTime", "AddedTime", "DataOffset"]
            #$d = explode("\t", $L);
            if ($L{0} === '#')
                continue;
            $p1 = strpos($L, "\t") + 1;
            $filename_hash = hex2bin(substr($L, $p1, 20)); // 20b (10 bytes int as hex)
            $this->KNOWN_FILE[$filename_hash] = 1;
            $dh = hex2bin(substr($L, $p1+21, 20)); // DataHash 20b (10 bytes int as hex)
            $p2 = strrpos($L, "\t") + 1;
            $offset = (int) substr($L, $p2);
            $this->DATAHASH_OFFSET[$dh] = $offset;
        }
        fclose($fh_index);
        $offset = filesize(Core::DATA);
        return $this->pack($this->newFileScanner(), $offset);
    }
    /**
     * Create new Archive, add all files
     * Options:
     *   --rewrite  - rewrite existing BigPack files
     */
    function init() : int { # NN files-added
        if (file_exists(Core::INDEX)) {
            if (@$this->opts['rewrite']) {
                unlink(Core::INDEX);
                unlink(Core::DATA);
                @unlink(Core::MAP);
                @unlink(Core::MAP2);
            } else {
                Util::error("BigPack files already exists - refusing to run\n".
                    "use --rewrite to *REMOVE OLD* archive (all archived files will be LOST), and build new one\n".
                    "Use 'bigpack add' to add new files");
            }
        }
        $archive_info = "BigPack".Core::VERSION." ".gmdate("Ymd His")." ".\get_current_user()."@".\gethostname();
        $this->_write("", join("\t", ["#FileName", "FilenameHash", "DataHash", "FilePerms", "FileMTime", "AddedTime", "DataOffset"])."\n", $archive_info);
        $offset = strlen($archive_info);
        return $this->pack($this->fileScanner(), $offset);
    }

    /**
     * read files from scanner, pack them into archive
     * --gzip - use gzip on data
     */
    function pack($scanner, $offset) : int { # NN files-added
        $this->fh_index = Util::openLock(Core::INDEX);
        $this->fh_data  = Util::openLock(Core::DATA);
        stream_set_write_buffer($this->fh_index, 1 << 16);
        stream_set_write_buffer($this->fh_data, 1 << 20);
        $this->stat['files'] = 0;
        $this->stat['file-size'] = 0;
        foreach ($scanner as [$file, $filename_hash]) {
            $data = file_get_contents($file);
            $data_hash = Core::hash($data);
            $flags = 0; // bit field
            $mode = fileperms($file) & 511;   // @todo check vs stat() - what is faster
            $file_mtime = filemtime($file);
            if (@$this->opts['gzip'])
                [$data, $flags] = $this->_compress($file, $flags, $data);
            # echo json_encode([$file, $mode, $file_mtime, $offset]), "\n";
            $offset += $this->write($file, $filename_hash, $data_hash, $mode, $file_mtime, $offset, $flags, $data);
        }
        $this->_flush(); // flush write buffer, remove archived files (if --rm)
        fclose($this->fh_data);
        fclose($this->fh_index);

        echo json_encode(['stats' => $this->stat]), "\n";
        return $this->stat['files'];
    }

    /**
     * try to compress data
     * - files with extensions listed in no-gzip excluded
     * - require at least 5% compression
     * - no compression for files less than 1024 bytes
     */
    function _compress(string $file, $flags, string $data) {
        if ($ext_p = strrpos($file, '.')) {
            $ext = substr($file, $ext_p+1);
            if (@$this->no_gzip[$ext]) {
                @$this->stat['files-compression-disallowed']++;
                return [$data, $flags]; // no compression for specific extensions
            }
        }
        $len = strlen($data);
        if ($len < 1024) {
            @$this->stat['files-compression-skipped']++;
            return [$data, $flags]; // no compression for small files
        }
        $compressed_data = gzdeflate($data);
        if (strlen($compressed_data) > $len*0.95) { // 5% compression required
            @$this->stat['files-non-compressed']++;
            return [$data, $flags]; // no compression if compression is bad
        }
        @$this->stat['files-compressed']++;
        return  [$compressed_data, $flags | Core::FLAG_GZIP];
    }

    // High Level write.
    // takes care of
    // - data packing
    // - deduplication
    // - statistics
    function write($file, $filename_hash, $data_hash, $mode, $file_mtime, $offset, $flags, $data) : int {  # written data size
        $len = strlen($data);
        if ($_offset = $this->DATAHASH_OFFSET[$data_hash] ?? 0) { // reusing already saved content
            $offset = $_offset;
            $data = false;
        }
        $index = join("\t", [$file, bin2hex($filename_hash), bin2hex($data_hash), sprintf("%o", $mode), $file_mtime, $this->now, $offset])."\n";
        @$this->opts['vv'] && print("$file($len) -> $offset\n");  // debug
        if ($data === false) {
            @$this->stat['dedup-files']++;
            @$this->stat['dedup-size'] += $len;
            $this->_write($file, $index, false);
            return 0;
        }
        // uint32 size, byte(size_high_byte) byte[10] data-hash, byte flags, byte[$len] data  // 16 byte prefix
        $len_byte9 = $len >> 32;
        $w_data = pack("Lca10c", $len, $len_byte9, $data_hash, $flags).$data;
        $this->_write($file, $index, $w_data);
        $this->DATAHASH_OFFSET[$data_hash] = $offset;
        @$this->stat['files']++;
        @$this->stat['file-size'] += $len;
        return Core::DATA_PREFIX + $len; // prefix(10 + 4 + 1) + data-len
    }

    // flush write buffer
    function _flush() {
        $this->_write("", "", true); // flush
    }

    // Low Level write with buffering
    // use $data === true to flush buffer
    // use $data === false to skip data entry
    function _write(string $file, string $index, $data) {
        static $files = []; // source files
        static $index_buffer = [];
        static $data_buffer = [];
        static $count = 0;
        static $size = 0;

        if ($file)
            $files[] = $file;
        if ($index)
            $index_buffer[] = $index;
        if ($data !== false && $data !== true) {
            $data_buffer[] = $data;
            $count++;
            $size += strlen($data);
        }

        if ($data === true) // FLUSH
            $count = static::$WRITE_BUFFER_FILES; // force write

        if ($count < static::$WRITE_BUFFER_FILES && $size < static::$WRITE_BUFFER_SIZE) {
            return;
        }

        // FLUSH !!!
        {
            // write index
            foreach ($index_buffer as $i)
                fwrite($this->fh_index, $i);
            // write data
            foreach ($data_buffer as $d)
                fwrite($this->fh_data, $d);

            if (@$this->opts['rm']) {
                foreach ($files as $f) {
                    @$this->opts['vv'] && print("remove $f\n");  // debug
                    unlink($f);
                }
            }
        }
        $index_buffer = [];
        $data_buffer = [];
        $files = [];
        $count = 0;
        $size  = 0;
    }

    /**
     * iterate over all files
     * remove files already in archive, use FILEMTIME to compare
     *
     *  -v to see files being removed
     */
    function removeArchived() {
        // [0 => "#FileName", 1 => "FilenameHash",  2 => "DataHash", 3 => "FilePerms", 4 => "FileMTime", 5 => "AddedTime", 6 => "DataOffset"]
        foreach (Core::indexReader() as $d) {
            $file = $d[0];
            if (! file_exists($file))
                continue;
            $file_mtime = filemtime($file);
            if ((int) $file_mtime === (int) $d[4]) {
                @$this->opts['v']  && print("removing $file\n");
                unlink($file);
                @$this->stat['files-removed']++;
            } else {
                fprintf(STDERR, "can't remove file %s. filemtime is different. expect %d got %d\n", $file, $d[4], $file_mtime);
            }
        }
        echo json_encode(['stats' => $this->stat]), "\n";
    }

} // class Packer

/**
 * Index-File Based Extractor
 */
class Extractor {

    // public
    var $opts = []; // options from BigPack.options and Cli

    function __construct(array $opts) {
        $this->opts = $opts;
    }

    function extract() {
        // processing CLI options
        if (@$this->opts['all'])
            return $this->extractAll();
        if (@$this->opts['data-hash'])
            return $this->extractHash($this->opts[2], $this->opts['data-hash']);  // extract filename --data-hash=....
        $f = function ($v, $k) { if ($k && is_int($k) && $k > 1) return $v; };
        $files = array_filter($this->opts, $f, ARRAY_FILTER_USE_BOTH);
        if (! $files)
            Util::error("no files to extract, specify list of files or '--all'");
        // ------------------------
        $fh2file = [];  // filehash => $filename
        $fh2d = []; // filehash => $index-line
        foreach ($files as $file)
            $fh2file[Core::hash($file)] = $file;
        foreach (Core::indexReader() as $d) {
            if (@$fh2file[$d[1]]) {
                unset($fh2file[$d[1]]); // extracted
                //$this->extract($d);   // we need last file revision - can't extract first occurence
                $fh2d[$d[1]] = $d;
            }
        }
        if ($fh2file)
            Util::error("Error: Refusing to extract\nFiles not found:\n  ".join("\n  ", $fh2file));
        foreach ($fh2d as $d)
            $this->_extract($d);
    }

    function extractAll() {
        foreach (Core::indexReader() as $d) {
            $this->_extract($d);
        }
    }

    // extract specific version of specific file
    // bigpack extract Filename --data-hash=....
    function extractHash(string $file, string $data_hash_hex) {
        if (! $file)
            Util::error("specify filename");
        $fh = Core::hash($file);
        if (! $data_hash_hex)
            Util::error("specify data-hash");
        $dh   = hex2bin($data_hash_hex);
        foreach (Core::indexReader() as $d) {
            if ($d[1] === $fh && $d[2] === $dh) {
                $this->_extract($d);
                return;
            }
        }
        Util::error("Error: Can't find filename - hash combination");
    }

    // Extract specific file
    // [0 => "#FileName", 1 => "FilenameHash",  2 => "DataHash", 3 => "FilePerms", 4 => "FileMTime", 5 => "AddedTime", 6 => "DataOffset"]
    function _extract(array $d) {
        // var_dump(['extract', $d]);
        [$file, $filename_hash, $data_hash, $mode, $file_mtime, $added_time, $offset] = $d;
        // var_dump(["offset" => $offset]);
        [$data, $dh] = Core::_readOffset((int) $offset);
        @$this->opts['vv'] && print("file: $file dh: ".bin2hex($data_hash)."\n");  // debug
        // var_dump(["file" => $file, "data" => $data]);
        if ($dh !== $data_hash) {
            $error = "File: $file data hash mismatch expected: ".bin2hex($data_hash)." got: ".bin2hex($dh)." read-data-size: ".strlen($data)." use --allow-mismatch to bypass broken files";
            echo "take 2 on hash:".bin2hex(Core::hash($data))."\n";
            if ($this->opts['allow-mismatch'])
                fwrite(STDERR, $error."\n");
            else
                Util::error($error); // && DIE !
        }
        if (strpos($file, '/'))
            Core::_makeDirs($file);
        if (file_exists($file) && ! @$this->opts['overwrite'])
            Util::error("Error: file $file already exists, specify --overwrite to overwrite");
        $r = file_put_contents($file, $data);
        if ($r === false) {
            Util::error("Can't write to file: $file, aborting");
        }
        touch($file, $file_mtime);
        chmod($file, $mode);
    }




} // class Packer


/**
 * "MAP" file bases extractor
 *
 * Can NOT preserve file_mtime and mode
 *
 * TEST:
 *   bigpack list --name-only | xargs -n 1 bigpack extractMap
 *   bigpack list --name-only | xargs -n 100 -P 10 bigpack extractMap
 */
class ExtractorMap {

     // public
    var $opts = []; // options from BigPack.options and Cli
    var $map = "";   // sorted list of ["filehash" (10 byte), "offset" (6 bytes)] records
    var $map_cnt = 0;   // count

    function __construct(array $opts) {
        $this->opts = $opts;
        $this->map = file_get_contents(Core::MAP);
        $this->map_cnt = strlen($this->map) >> 4;
    }


    function extract() {
        $f = function ($v, $k) { if ($k && is_int($k) && $k > 1) return $v; };
        $files = array_filter($this->opts, $f, ARRAY_FILTER_USE_BOTH);
        if (! $files)
            Util::error("no files to extract, specify list of files or '--all'");
        $cnt = 0;
        foreach ($files as $file) {
            if (file_exists($file) && ! @$this->opts['overwrite'])
                Util::error("Error: file $file already exists, specify --overwrite to overwrite");
            $this->_extract($file);
            $cnt++;
        }
        echo "$cnt files extracted\n";
    }

    /**
     * extract file via filehash
     */
    function _extract(string $file) {
        $fh = Core::hash($file);
        $offset = $this->_offset($fh);
        if ($offset === 0)
            Util::error("No such file $file in archive, aborting"); // && die
        if (strpos($file, '/'))
            Core::_makeDirs($file);
        [$data, $dh] = Core::_readOffset((int) $offset);
        $r = file_put_contents($file, $data);
        if ($r === false)
            Util::error("Can't write to file: $file, aborting");
        # echo $file, "\n";
    }

    /**
     * Binary Search In MAP
     * TODO: use MAP2
     * TODO: use MAPH - 16BIT index for MAP
     */
    function _offset(string $fh) : int {
        // MAP only version
        $from = 0;
        $to   = $this->map_cnt;
        // $MAP is sorted list of ["filehash" (10 byte), "offset" (6 bytes)] records (256TB addressable)
        while (1) {
            $pos = ($from + $to) >> 1;
            # echo "$from <$pos> $to\n";
            $cfh = substr($this->map, $pos << 4, 10);  // 10 - FileHash length
            $cmp = strncmp($fh, $cfh, 10);
            if (! $cmp) { # found it
                $offset_pack = substr($this->map, ($pos << 4) + 10, 6);
                return unpack("Pd", $offset_pack."\0\0")['d'];
            }
            if ($pos === $from)
                return 0;
            if ($cmp > 0) {
                $from = $pos;
            } else {
                $to = $pos;
            }
            if ($from === $to)
                return 0;
        }
        return 0;
    }
}

/**
 * "MAP2" file bases extractor
 * Can NOT preserve file_mtime and mode
 */
class ExtractorMap2 {

    //
    // TODO
    //

}

/**
 * Build
 * "map" - index of "index"
 * "map2" - index of "map"
 */
class Indexer {

    // public
    var $opts = []; // options from BigPack.options and Cli

    var $FH2OFFSET = []; // array of "filehash" (10 byte)."offset" (6 bytes)

    var $FHP2MI = []; // FileHashPrefix(16bit) => MapIndex(UINT32) (MAPH map Hash index)

    function __construct(array $opts) {
        $this->opts = $opts;
    }

    function index() {
         //      [0 => "#FileName", 1 => "FilenameHash",  2 => "DataHash", 3 => "FilePerms", 4 => "FileMTime", 5 => "AddedTime", 6 => "DataOffset"]
        foreach (Core::indexReader() as $d) {
            $b_offset = substr(pack("P", $d[6]), 0, 6); // 64bit INT, little endian
            $this->FH2OFFSET[] = $d[1].$b_offset;
        }
        sort($this->FH2OFFSET);
        //var_dump(count($this->FH2OFFSET));
        $this->buildMap();
        $this->buildMap2();
        $this->buildMapH();
    }

    /**
     * BigPack.map is binary file
     * sorted list of ["filehash" (10 byte), "offset" (6 bytes)] records (256TB addressable)
     */
    function buildMap() {
        $fh_map = Util::openLock(Core::MAP, "wb");
        stream_set_write_buffer($fh_map, 1 << 20);
        foreach ($this->FH2OFFSET as $d)
            fwrite($fh_map, $d);
        fclose($fh_map);
        echo "MAP: ".count($this->FH2OFFSET), " items \n";
        reset($this->FH2OFFSET);
        echo "  FirstEntry: ".bin2hex($this->FH2OFFSET[0])."\n";
        echo "  LastEntry:  ".bin2hex($this->FH2OFFSET[count($this->FH2OFFSET)-1])."\n";
    }

    function buildMap2() {
        $fh_map = Util::openLock(Core::MAP2, "wb");
        stream_set_write_buffer($fh_map, 1 << 20);
        $len = count($this->FH2OFFSET);
        $cnt = 0;
        for ($i = 0; $i < $len; $i+=512) {
            // fwrite($fh_map, $this->FH2OFFSET[$i]);  -- NEED ONLY 10-BYTE FILEHASH
            $cnt++;
        }
        fclose($fh_map);
        echo "MAP2: ".number_format($cnt)." items\n";
    }

    /**
     * Build FileHash to MapIndex Mapping
     * 16-bit-filehash-prefix => FIRST-MAP-ITEM-NN
     * unmapped items points to 0xFFFFFFFF item
     */
    function buildMapH() {
        $NIL = 0xFFFFFFFF; // no data
        foreach (range(0, 65535) as $i)
            $this->FHP2MI[$i] = $NIL;  // NO DATA FOUND
        $cnt = 0;
        foreach ($this->FH2OFFSET as $i => $fho) {
            $fhp = unpack("vd", substr($fho, 0, 4))['d'];
            if ($this->FHP2MI[$fhp] === $NIL) {
                $this->FHP2MI[$fhp] = $i;
                $cnt++;
            }
        }
        $fh_map = Util::openLock(Core::MAPH, "wb");
        stream_set_write_buffer($fh_map, 1 << 20);
        foreach ($this->FHP2MI as $mi)
            fwrite($fh_map, pack("V", $mi)); // map-indexes as uint32
        fclose($fh_map);
        // var_dump($this->FHP2MI);
        echo "MAP-HASH: ".number_format($cnt)." items\n";
    }
}

/**
 * CLI Interface:
 *    function-name = Cli Tool Command Name
 *    class php-doc = global doc
 *    function php-doc = method doc, first line = method description for global doc
 */

/**
 * * File Compressor with Deduplication.
 * * Blazing Fast Static Web Server.
 * * Petabyte Scale.
 * * Serve Millions Files from several Indexed Archive File(s)
 *
 * run: "bigpack help $command" to see doc for specific commands
 *
 * read doc: https://github.com/homebase/bigpack/blob/master/README.md
 */
class Cli extends CliTool {

    /**
     * create new archive
     * pack all files in directory and subdirectories
     * Options:
     *   --rewrite  - rewrite existing BigPack files
     *   --gzip     - try to compress files
     */
    static function init(array $opts) {
        if ((new Packer($opts))->init()) // if files-added
            (new Indexer($opts))->index();
    }

    /**
     * add new files to existing archive
     * Options:
     *   --gzip     - try to compress files
     */
    static function add(array $opts) {
        if ((new Packer($opts))->add())  // if files-added
            (new Indexer($opts))->index();
    }


    /**
     * list files in archive
     * Option: --name-only - show only file names
     */
    static function list(array $opts) {
        if ($opts['name-only']) {
            foreach (Core::indexReader() as $d)
                echo $d[0], "\n";
            return;
        }
        echo shell_exec("cat BigPack.index | column -t");
        echo "Files in archive: ", number_format( (int) shell_exec("cat BigPack.index | wc -l") ), "\n";
    }

    /**
     * extract all or specific files from archive
     *
     * Usage: bigpack extract file1 file2 file3 ...
     *
     * Options:
     *  --all      - extract all files
     *  --data-hash     - extract ONE file with specific data-hash (e.g. specific version of file)
     *                    run "bigpack list" to see all files aand data-hashes
     */
    static function extract(array $opts) {
        (new Extractor($opts))->extract();
    }

    /**
     * extract files from archive. (TEST)
     * Lookups done via "MAP2" / "MAP" file
     */
    static function extractMap(array $opts) {
        (new ExtractorMap($opts))->extract();
    }

    /**
     * remove alredy archived files
     * will not remove modified files (when archive version != file version)
     *
     */
    static function removeArchived(array $opts) {
        (new Packer($opts))->removeArchived();
    }


    /**
     * Build "map", "map2" files. (index indexes)
     * - "map" (index of index)                  - list of [filehash => offset] (full list)
     * - "map2" (index of map(index of index))   - list of [filehash => offset] (one record for 512 "map" entries)
     */
    static function index(array $opts) {
        (new Indexer($opts))->index();
    }


}
