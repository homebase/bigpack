package bigpack

import (
    "crypto/md5"
    "os"
    "log"
)

const (
    // filenames
    FILE_INDEX = "BigPack.index"
    FILE_DATA  = "BigPack.data"
    FILE_MAP   = "BigPack.map"
    FILE_MAP2  = "BigPack.map2"
    FILE_MAPH  = "BigPack.maph" // map hash. top-16bit of filenamehash => map-item-nn
    FILE_OPTIONS  = "BigPack.options" // key=value file, properties/*.ini format

    // bitmap flags
    FLAG_GZIP    = 1
    FLAG_DELETED = 2;

    FILE_DATA_PREFIX = 16; // bytes
)

type (
    bphash [10]byte
)

// Generic function
func ReadFileSlice(filename string, offset int, count int)  (data []byte, cnt int) {
   file, _ := os.Open(filename)
   defer file.Close()
   _, err := file.Seek(int64(offset), 0) // from beginning of file
   if err != nil {
      log.Fatal(err)
   }
   data = make([]byte, count)
   cnt, err = file.Read(data)
   if err != nil {
      log.Fatal(err)
   }
   return
}

func ReadOffset(offset int) (string, bphash, byte) {
    data, cnt := ReadFileSlice(FILE_DATA, offset, 16384) // 16K
    var dh bphash
    // PREFIX IS:
    //    uint32 size, byte size_high_byte, byte[10] data-hash, byte flags, byte[$len] data  // 16 byte prefix


    flags := 0
    return "DATA", bpHash("DATA"),  byte(flags)
    /*
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
     */
}

func bpHash(data string) (bh bphash) {
   r := md5.Sum([]byte(data))
   copy(bh[:], r[0:10])  // convert []byte to [10]byte
   return
   // return bphash {r[0],r[1],r[2],r[3],r[4],r[5],r[6],r[7],r[8],r[9]}
}