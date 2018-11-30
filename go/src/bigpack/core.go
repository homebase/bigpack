package bigpack

import (
    "crypto/md5"
    "os"
    "log"
    "encoding/binary"
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

    // Data Read Buffer
    DATA_READ_BUFFER = 16384  // 16K
)

type (
    bphash [10]byte
)

// Generic function
// Read Slice of File
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

// extract STORED-FILE-DATA from given offset at DATAFILE
func ReadDataOffset(offset int) (data []byte, dh bphash, flags byte) { // data, bpHash(data), flags
    file, _ := os.Open(FILE_DATA)
    defer file.Close()
    _, err := file.Seek(int64(offset), 0) // from beginning of file
    if err != nil {
        log.Fatal(err)
    }
    data = make([]byte, DATA_READ_BUFFER)
    _, err = file.Read(data)
    if err != nil {
        log.Fatal(err)
    }

    // PREFIX IS:
    //    uint32 size, byte size_high_byte, byte[10] data-hash, byte flags, byte[$len] data  // 16 byte prefix
    size := int(binary.LittleEndian.Uint32(data[0:4])) + (int(data[4]) << 32)
    copy(dh[:], data[6:16])  // convert slice to [10]byte
    flags = data[15]
    if flags & FLAG_DELETED > 0 {
        return data[0:0], bphash{}, flags
    }
    remaining := size - DATA_READ_BUFFER + FILE_DATA_PREFIX
    // fmt.Printf("size,offset ", size, offset, remaining)
    if remaining <= 0 { // we read extra data - cut is off
        data = data[FILE_DATA_PREFIX:FILE_DATA_PREFIX+size]
    } else { // have to read more
        data2 := make([]byte, remaining)
        len2, err := file.Read(data2)
        if err != nil {
            log.Fatal(err)
        }
        if len2 != remaining {
            log.Fatal("DataFile second-read less data than expected")
        }
        data = append(data, data2...)
    }
    return
}

func bpHash(data string) (bh bphash) {
   r := md5.Sum([]byte(data))
   copy(bh[:], r[0:10])  // convert []byte to [10]byte
   return
}