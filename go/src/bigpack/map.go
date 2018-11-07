package bigpack

import (
    "os"
    "log"
)

type (

    MAP struct {
        data  []byte
        cnt   int
    }

    MAP2 struct {
        data  []byte
        cnt int
    }

)

/**
 * read 8k block from MAP file
 */
func (m MAP) Read(block int64) {
   offset := block * 8192
   file, _ := os.Open(FILE_MAP)
   defer file.Close()
   _, err := file.Seek(offset, 0) // from beginning of file
   if err != nil {
      log.Fatal(err)
   }
   m.data = make([]byte, 8192)
   cnt, err := file.Read(m.data)
   if err != nil {
      log.Fatal(err)
   }
   m.cnt = cnt >> 4  // 16byte items
}

/**
 * Binary Search In MAP
 * @return int 0 - File Not Found, 1 - Error, 10+ offset in DATA file
 *
 */
func (m MAP) Offset(fh bphash) int { // offset
    /*
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
    */
    return 0;
}


/**
 * NON-EXACT BinarySearch of MAP2 index
 * return NN-of-(8kb)block-in-MAP file
 */
func (m MAP2) Index(fh bphash) int { // INDEX(NN) of 8K block in-MAP file
    /*
    $from = 0;
    $to   = $this->map_cnt;
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
    */
    return 0;
}