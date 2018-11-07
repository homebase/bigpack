<?php

/**
 *
 * TODO:
 *
 *   5. Service - adding files in runtime - buffer + rebuild
 *      new-files buffer, then merge-sort + index rebuild
 *
 *   6. [??] MAPH index use. seems like benefits are negligible. MAP2 is better
 *
 * Global Options:
 *     dir  : dir where to take source files
 *     v    : verbose
 *     vv   : very-verbose (show debug info)
 *
 * Packer options:
 *     gzip=0  : disable gzip
 *     gzip=1  : enable gzip (default)
 *     skip-gzip : space delimited list of file extensions not to even-try to compress. use "--vv" to see default list
 *     rm      : remove archived files
 *
 * testing:
 *   ./bigpack init --recreate --vv -v
 *   ./bigpack add  --vv -v
 *
 *
 * @todo :
 *    delete-content - mark content as deleted (e.g. DMCA request)
 *                     deleteContent()
 *    store directories (along with permissions)
 *    remove directories (when --rm)
 *       save directory list, try to remove non-empty dirs after
 *
 *    "diff --hard" - iterate over known files. compare Core::hash(content
 *    gzipped files and HTTP server !! - DO NOT NEED TO DECOMPRESS
 *
 * ISSUES: directories - creation / removal / storing
 *
 */


// HB = homebase framework namespace
namespace hb\bigpack;

declare(ticks = 1);

use hb\util\CliTool;
use hb\util\Util;

/**
 * Common methods
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
    CONST OPTIONS  = 'BigPack.options'; // key=value file, php.ini format

    CONST VERSION = '1.0.1'; // semver

    // Signals
    static $STOP_SIGNAL = 0; // kill -SIGINT / -SIGTERM $PID
    static $RELOAD_SIGNAL = 0; // kill -HUP $PID


    /**
     * Generator
     *   return items in form:
     *      [0 => "#FileName", 1 => "FilenameHash",  2 => "DataHash", 3 => "FilePerms", 4 => "FileMTime", 5 => "AddedTime", 6 => "DataOffset"]
     */
    static function indexReader() {
        file_exists(Core::INDEX) or Util::error("Can't find index file ".Core::INDEX);
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
    static function _readOffset(int $offset, $raw = false) : array { # [data, data-hash] || [data, data-hash, $is_gzip]
        static $READ_BUFFER = 1024 * 16; // 16K
        // read 16K
        $fh = fopen(Core::DATA, "rb");
        // DATA IS: Prefix + $data
        //    pack("LA10c", $len, $data_hash, $flags).$data;
        // PREFIX IS:
        //    uint32 size, byte size_high_byte, byte[10] data-hash, byte flags, byte[$len] data  // 16 byte prefix
        fseek($fh, $offset, SEEK_SET);
        $data = fread($fh, $READ_BUFFER);
        $d = unpack("Lsize/chsize/a10dh/cflag", $data); // LOWERCASE "a", uppercase "A" corrupt data
        // var_dump([$offset, $d]);
        if ($d['size'] <= $READ_BUFFER - Core::DATA_PREFIX) { // prefix size
            $data = substr($data, Core::DATA_PREFIX, $d['size']);
        } else {
            $data = substr($data, Core::DATA_PREFIX);
            $d['size'] += $d['hsize'] << 32; // High Byte #5
            $remaining = $d['size'] - (Core::DATA_PREFIX - $READ_BUFFER);
            $data = $data.fread($fh, $remaining);
        }
        fclose($fh);
        if ($d['flag'] & Core::FLAG_DELETED)
            return ["", "", $d['flag']]; // File Deleted
        if ($raw)
            return [$data, $d['dh'], $d['flag'] & Core::FLAG_GZIP];
        if ($d['flag'] & Core::FLAG_GZIP) {
            $data = gzinflate($data);
            $d['flag'] ^= Core::FLAG_GZIP; // gzip no more
        }
        return [$data, $d['dh'], $d['flag']];
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

    static function _SIGINT() {
        echo "INT\n";
        self::$STOP_SIGNAL = "INT";
    }

    static function _SIGTERM() {
        echo "TERM\n";
        self::$STOP_SIGNAL = "TERM";
    }

    static function _SIGHUP() {
        echo "HUP\n";
        self::$RELOAD_SIGNAL = "HUP";
    }

}

class Packer {

    static $WRITE_BUFFER_FILES   = 10000;        // NN files to keep in write buffer
    static $WRITE_BUFFER_SIZE    = 100 << 20;     // size in MB
    static $skip_gzip_default = "gz bz2 tgz xz jpg jpeg gif png webp zip 7z rar";

    // never add this files to BigPack archive
    static $EXCLUDE_FILES = [Core::INDEX => 1, Core::DATA => 1, Core::MAP => 1, Core::MAP2 => 1, Core::MAPH => 1, Core::OPTIONS => 1];

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
    private  $skip_gzip; //

    function __construct(array $opts) {
        $this->opts = $opts;
        $this->now = time();
        $this->dir = $this->opts["dir"] ?? "."; // "--dir=xxx" or current dir
        $this->opts["gzip"] = $this->opts["gzip"] ?? 1; // Default GZIP is ON
        $skip_gzip = $opts['skip-gzip'] ?? self::$skip_gzip_default; // space delimited list of extensions
        $this->skip_gzip = array_flip(explode(" ", ' '.$skip_gzip)); // ext => 1
        if ($sf = @$this->opts['skip-files']) { # COMMA DELIMITED FILE-NAME LIST - files will be excluded in ALL directories
            foreach (explode(",", $sf) as $file)
                self::$EXCLUDE_FILES[$file] = 1;
        }
        if (@$args['vv'])  // -vv = very-verbose
            echo json_encode(['options' => $args, 'skip-gzip' => $this->skip_gzip]), "\n";
    }



    /**
     * Generator - scan files in directory
     * returns filename and its hash
     */
    function fileScanner() { # Generator that yields [fileName, filenameHash]
        // skip BigData Archive Files
        $fileCallback = function($dir, $file) {
            if (@Packer::$EXCLUDE_FILES[$file])
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
     *  Add NEW Files to existing  archive
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
     *   --recreate
     *   --bare
     */
    function init() : int { # NN files-added
        if (file_exists(Core::INDEX)) {
            if (@$this->opts['recreate']) {
                unlink(Core::INDEX);
                unlink(Core::DATA);
                @unlink(Core::MAP);
                @unlink(Core::MAP2);
            } else {
                Util::error("BigPack files already exists - refusing to run\n".
                    "use --recreate to *REMOVE OLD* archive (all archived files will be LOST), and build new one\n".
                    "Use 'bigpack add' to add new files");
            }
        }
        $archive_info = "BigPack".Core::VERSION." ".gmdate("Ymd His")." ".\get_current_user()."@".\gethostname();
        $this->_write("", join("\t", ["#FileName", "FilenameHash", "DataHash", "FilePerms", "FileMTime", "AddedTime", "DataOffset"])."\n", $archive_info);
        $offset = strlen($archive_info);
        if (@$this->opts['bare'])
            return $this->pack([], $offset);
        return $this->pack($this->fileScanner(), $offset);
    }


    /**
     * Create/Add Archive from pre-generated filelist
     * generate filelist with: bigpack generateFileList
     *
     * To Stop do 'kill $process_pid'
     */
    function addFromFileList() : int {
        static $filelist = "filelist.bigpack.gz";
        if (! file_exists($filelist))
            Util::error("no $filelist file found, generate one with bigpack generateFileList");
        if (! file_exists(Core::INDEX)) {
            echo "Creating Archive\n";
            $this->opts['bare'] = 1;
            $this->init();
        }
        // ignore existing files
        foreach (Core::indexReader() as $d)
            $this->KNOWN_FILE[$d[1]] = 1;
        // ignore system files (just in case)
        foreach ([Core::INDEX, Core::DATA, Core::MAP, Core::MAP2, Core::MAPH, Core::OPTIONS, $filelist] as $file)
            $this->KNOWN_FILE[Core::hash($file)] = 1;
        $offset = filesize(Core::DATA);
        $this->fh_index = Util::openLock(Core::INDEX);
        $this->fh_data  = Util::openLock(Core::DATA);
        stream_set_write_buffer($this->fh_index, 1 << 16);
        stream_set_write_buffer($this->fh_data, 1 << 20);
        $this->stat['files'] = 0;
        $this->stat['file-size'] = 0;
        $cnt = 0;
        foreach (Util::gzLineReader($filelist) as $line) {
            [$file, $mode, $file_mtime] = explode("\t", $line);
            if ($file{0}.$file{1} === './') // cut useless "./" prefix
                $file = substr($file, 2);
            $filename_hash = Core::hash($file);
            if (@$this->KNOWN_FILE[$filename_hash]) {
                @$this->stat['known-files']++;
                continue;
            }
            $data = file_get_contents($file);
            $data_hash = Core::hash($data);
            $flags = 0; // bit field
            $mode = (int) ($mode & 511);
            $file_mtime = (int) $file_mtime;
            if (@$this->opts['gzip'])
                [$data, $flags] = $this->_compress($file, $flags, $data);
            # echo json_encode([$file, $mode, $file_mtime, $offset]), "\n";
            $offset += $this->write($file, $filename_hash, $data_hash, $mode, $file_mtime, $offset, $flags, $data);
            $cnt++;
            if (Core::$STOP_SIGNAL) {
                echo "Stopping Packer in STOP Signal, doing final flush\n";
                break;
            }
        }
        $this->_flush(); // flush write buffer, remove archived files (if --rm)
        fclose($this->fh_data);
        fclose($this->fh_index);
        echo "\nDONE. $cnt files added\n";
        return $cnt;
    }


    /**
     * read files from scanner, pack them into archive
     * --gzip - use gzip on data
     */
    function pack($scanner, $offset) : int { # NN files-added
        $pid = getmypid();
        echo "Starting Packer. Use \"kill $pid\" to safe-stop process\n";
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
            if (Core::$STOP_SIGNAL) {
                echo "Stopping Packer in STOP Signal, doing final flush\n";
                break;
            }
        }
        $this->_flush(); // flush write buffer, remove archived files (if --rm)
        fclose($this->fh_data);
        fclose($this->fh_index);

        echo "\nDONE\n";
        # echo json_encode(['stats' => $this->stat]), "\n";
        return $this->stat['files'];
    }

    /**
     * try to compress data
     * - files with extensions listed in skip-gzip excluded
     * - require at least 5% compression
     * - no compression for files less than 1024 bytes
     */
    function _compress(string $file, $flags, string $data) {
        if ($ext_p = strrpos($file, '.')) {
            $ext = substr($file, $ext_p+1);
            if (@$this->skip_gzip[$ext]) {
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
            echo json_encode(['stats' => $this->stat]), "\n";
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
     *  --force - ignore file-modification-time difference
     */
    function removeArchived() {
        // [0 => "#FileName", 1 => "FilenameHash",  2 => "DataHash", 3 => "FilePerms", 4 => "FileMTime", 5 => "AddedTime", 6 => "DataOffset"]
        foreach (Core::indexReader() as $d) {
            $file = $d[0];
            if (! file_exists($file))
                continue;
            $file_mtime = filemtime($file);
            if (@$this->opts['force'])
                $file_mtime = (int) $d[4];
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

    /**
     * mark file-content as deleted
     *
     * Important: content is still kept in data file, however you can not extract it unless you undelete it
     *
     * --undelete - remove is-deleted flag
     */
    function deleteContent() {
        $f = function ($v, $k) { if ($k && is_int($k) && $k > 1) return $v; };
        $files = array_filter($this->opts, $f, ARRAY_FILTER_USE_BOTH);
        if (! $files)
            Util::error("no files given, specify list of files");
        foreach ($files as $f)
            $this->_deleteContent($f);
    }

    function _deleteContent(string $file) {
        #die("TODO");
        $fh = Core::hash($file);
        $offset = (new ExtractorMap2([]))->_offset($fh);
        if ($offset === 0)
            Util::error("No such file $file in archive, aborting"); // && die
        if ($offset === 1)
            Util::error("File $file extract error"); // && die
        // uint32 size, byte(size_high_byte) byte[10] data-hash, byte flags, byte[$len] data  // 16 byte prefix
        // FLAGS is last BYTE of DATA
        $fh_data  = Util::openLock(Core::DATA, "r+b");
        fseek($fh_data, $offset + 15);
        $flags = ord(fread($fh_data, 1));
        // var_dump([$file, $offset]);
        //$flags = unpack("Cd", $flags_s)['d'];
        echo "Current Delete Flag: ", (bool) ($flags & Core::FLAG_DELETED),"\n";
        $flags |= Core::FLAG_DELETED;
        if (@$this->opts['undelete'])
            $flags ^= Core::FLAG_DELETED;
        echo "New Delete Flag: ", (bool) ($flags & Core::FLAG_DELETED),"\n";
        fseek($fh_data, $offset + 15);
        fwrite($fh_data, chr($flags), 1);
        fclose($fh_data);
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

    // extract one or more files
    // --cat = dump file to stdout
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
        if (! $data && ! $dh)
            Util::error("File $file deleted, aborting");
        if ($dh !== $data_hash) {
            $error = "File: $file data hash mismatch expected: ".bin2hex($data_hash)." got: ".bin2hex($dh)." read-data-size: ".strlen($data)." use --allow-mismatch to bypass broken files";
            echo "take 2 on hash:".bin2hex(Core::hash($data))."\n";
            if ($this->opts['allow-mismatch'])
                fwrite(STDERR, $error."\n");
            else
                Util::error($error); // && DIE !
        }
        if (@$this->opts['cat']) {
            echo $data;
            return;
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

    // bigpack list
    // bigpack list "path/file_shell_wildcard" <<  "*?[abc]" see http://php.net/manual/en/function.fnmatch.php
    // Options:
    //  --name-only
    //  --raw
    function list() {
        if (@$this->opts['name-only']) {
            foreach (Core::indexReader() as $d)
                echo $d[0], "\n";
            return;
        }
        if (@$this->opts['raw']) {
            echo shell_exec("cat BigPack.index | column -t");
            echo "Files in archive: ", number_format( (int) shell_exec("cat BigPack.index | wc -l") ), "\n";
            return;
        }
        $pattern = "".@$this->opts[2];
        // [0 => "#FileName", 1 => "FilenameHash",  2 => "DataHash", 3 => "FilePerms", 4 => "FileMTime", 5 => "AddedTime", 6 => "DataOffset"]
        foreach (Core::indexReader() as $d) {
            if ($pattern && ! fnmatch($pattern, $d[0]))
                continue;
            $d[1] = bin2hex($d[1]);
            $d[2] = bin2hex($d[2]);
            $d[3] = base_convert($d[3], 10, 8);
            $d[4] = date("Y-m-d H:i:s", $d[4]);
            $d[5] = date("Y-m-d H:i:s", $d[5]);
            $d[6] = number_format($d[6]);
            echo join("\t", $d), "\n";
        }
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
 *
 * Debug: view MAP file: xxd -c 16  BigPack.map
 */
class ExtractorMap {

     // public
    var $opts = []; // options from BigPack.options and Cli
    var $map = "";   // sorted list of ["filehash" (10 byte), "offset" (6 bytes)] records
    var $map_cnt = 0;   // count

    function __construct(array $opts) {
        $this->opts = $opts;
        $this->init();
    }

    function init() {
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
        if ($offset === 1)
            Util::error("File $file extract error"); // && die
        [$data, $dh] = Core::_readOffset((int) $offset);
        if (! $data && ! $dh)
            Util::error("File $file deleted, aborting");
        if (strpos($file, '/'))
            Core::_makeDirs($file);
        $r = file_put_contents($file, $data);
        if ($r === false)
            Util::error("Can't write to file: $file, aborting");
        # echo $file, "\n";
    }

    /**
     * Binary Search In MAP
     * @return int 0 - File Not Found, 1 - Error, 10+ offset in DATA file
     *
     */
    function _offset(string $fh) : int {
        // MAP only version
        $from = 0;
        $to   = $this->map_cnt;
        // $MAP is sorted list of ["filehash" (10 byte), "offset" (6 bytes)] records (256TB addressable)
        while (1) {
            $pos = ($from + $to) >> 1;
            // echo "$from <$pos> $to\n";
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
 * DEBUG: view MAP2 file: xxd -c 10  BigPack.map2
 * TEST: bigpack list --name-only | xargs -n 300 bigpack extractMap2
 */
class ExtractorMap2 extends ExtractorMap {

    function init() {
        $this->map = file_get_contents(Core::MAP2);
        $this->map_cnt = strlen($this->map) / 10;  // 10 byte items
    }

    /**
     * Binary Search In MAP2
     * Load MAP Block
     * Binary Search In MAP
     * TODO: use MAPH - 16BIT index for MAP << NO
     * @return int 0 - File Not Found, 1 - Error, 10+ offset in DATA file
     */
    function _offset(string $fh, $retry = 0) : int {
        $block = $this->_mapIndex($fh);
        // Util::error("block #".$block);
        $H = new _ExtractorMapBlock(['block' => $block]);
        $expected_start = substr($this->map, $block*10, 10);
        $got_start = substr($H->map, 0, 10);
        if (! strncmp($expected_start, $got_start, 10))
            return $H->_offset($fh);
        if ($retry) {
            fprintf(STDERR, "MAP and MAP2 files still out of sync (after retry)- Refusing to serve files\n");
            return 1; // File Not Found
        }
        // MAP2 and MAP out of SYNC
        fprintf(STDERR,
            "Cached MAP2 and On-disk MAP files out of sync\n".
            "  Have you uploaded new MAP file?\n".
            "  expected-block-start: ".bin2hex($expected_start)." got: ".bin2hex($got_start)."\n".
            "  RE-READING MAP2 file\n"
        );
        $this->init();
        return $this->_offset($fh, 1);
    }

    /**
     * NON-EXACT BinarySearch of MAP2 index
     * return NN-of-(8kb)block-in-MAP file
     */
    function _mapIndex(string $fh) : int { # NN-of-block-in-MAP file
        $from = 0;
        $to   = $this->map_cnt;
        // $MAP is sorted list of "filehash" (10 byte)
        while (1) {
            $pos = ($from + $to) >> 1;
            # echo "$from <$pos> $to\n";
            $cfh = substr($this->map, $pos * 10, 10);  // 10 - FileHash length
            $cmp = strncmp($fh, $cfh, 10);
            if (! $cmp) { # found it
                return $pos;
            }
            if ($pos === $from) {
                return $pos;
            }
            if ($cmp > 0) {
                $from = $pos;
            } else {
                $to = $pos;
            }
            if ($from === $to) {
                return $pos;
            }
        }
        return 0;
    }

}

/**
 * internal helper for MAP2 extractor
 */
class _ExtractorMapBlock extends ExtractorMap {

    function init() {
        $block = $this->opts['block'];
        $fh = fopen(Core::MAP, "rb");
        fseek($fh, $block * 8192);
        $this->map = fread($fh, 8192);
        $this->map_cnt = strlen($this->map) >> 4;  // 16 byte items
    }

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
        // $this->buildMapH(); - DO NOT SEE ANY REASON to use MAPH. MAP2+MAP is already FAST enough - at least on my tests
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
        echo "MAP: ".number_format(count($this->FH2OFFSET)), " items \n";
        // echo "  FirstEntry: ".bin2hex($this->FH2OFFSET[0])."\n";  << DEBUG ONLY
        // echo "  LastEntry:  ".bin2hex($this->FH2OFFSET[count($this->FH2OFFSET)-1])."\n";
    }

    /**
     * BigPack.map2 is binary file
     * 1/512 subset of BigPack.map
     * sorted list of "filehash" (10 byte)
     */
    function buildMap2() {
        $fh_map = Util::openLock(Core::MAP2, "wb");
        stream_set_write_buffer($fh_map, 1 << 20);
        $len = count($this->FH2OFFSET);
        $cnt = 0;
        for ($i = 0; $i < $len; $i+=512) {
            fwrite($fh_map, substr($this->FH2OFFSET[$i], 0, 10)); // NEED ONLY 10-BYTE FILEHASH
            $cnt++;
        }
        fclose($fh_map);
        echo "MAP2: ".number_format($cnt)." items\n";
    }

    /**
     * Build FileHash to MapIndex Mapping
     * 16-bit-filehash-prefix => FIRST-MAP-ITEM-NN  --- FILE GZIPPED !!!
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
        $s = [];
        foreach ($this->FHP2MI as $mi)
            $s[] = pack("V", $mi);
        $file = Core::MAPH;
        $s = gzdeflate(join("", $s), 9);
        $r = file_put_contents($file, $s);
        if ($r === false)
            Util::error("Can't write to file: $file, aborting");
        #$fh_map = Util::openLock(Core::MAPH, "wb");
        #    fwrite($fh_map, pack("V", $mi)); // map-indexes as uint32
        #fclose($fh_map);
        // var_dump($this->FHP2MI);
        echo "MAP-HASH: ".number_format($cnt)." items\n";
    }
}


