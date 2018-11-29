package bigpack

import (
    "bytes"
    // "sync/atomic"
    "io/ioutil"
    "fmt"
    "encoding/binary"
)

type (

    MAP struct {
        data  []byte
        cnt   int
    }

    MAP2 struct {
        data  []byte
        cnt   int
        imap   MAP
    }

)

/**
 * read 8k block from MAP file
 */
func (m *MAP) Read(block int) {
   cnt := 0
   m.data, cnt = ReadFileSlice(FILE_MAP, block * 8192, 8192)
   m.cnt = cnt >> 4  // 16 byte block
   fmt.Printf("MAP read block %v cnt: %v\n", block, m.cnt)
}

/**
 * Binary Search In MAP Block
*  MAP.data is sorted list of ["filehash" (10 byte), "offset" (6 bytes)] records (256TB addressable)
 * @return int 0 - File Not Found, 1 - Error, 10+ offset in DATA file
 */
func (m *MAP) Offset(fh bphash) (offset int) {
    from := 0
    to := m.cnt
    offset = 0
    pos := 0
    for {
        pos = (from + to) >> 1
        dp := pos << 4
        cfh := m.data[dp:dp+10]
        cmp := bytes.Compare(fh[:], cfh)  // convert [10]byte to slice
        if cmp == 0 {
            //offset = binary.LittleEndian.Uint64(append(m.data[dp+10 : dp+16], 0, 0))  // 6-byte UINT => int
            return int( binary.LittleEndian.Uint64(append(m.data[dp+10 : dp+16], 0, 0)) ) // 6-byte UINT => int
        }
        if pos == from {
            return
        }
        if cmp > 0 {
            from = pos
        } else {
            to = pos
        }
        if from == to {
            return
        }
    }
    return
}



var (
    map2_read_lock uint32
)

/**
 * read MAP2 file into memory
 */
func (m *MAP2) Read() {
    // only one parallel execution allowed
    // other parallel requests ignored
 //   if !atomic.CompareAndSwapUint32(&map2_read_lock, 0, 1) {
 //       return
 //   }
 //   defer atomic.StoreUint32(&map2_read_lock, 0)
    //
    data, err := ioutil.ReadFile(FILE_MAP2)
    if err != nil {
        panic(err)
    }
    m.data = data
    m.cnt = len(m.data) / 10
    fmt.Printf("%s Init. count=%d\n", FILE_MAP2, m.cnt)
}


/**
 * NON-EXACT BinarySearch of MAP2 index
 * MAP2.data is sorted list of "[10]byte filehash"
 * @return NN-of-(8kb)block-in-MAP file
 */
func (m *MAP2) Index(fh bphash) (pos int) { // INDEX(NN) of 8K block in-MAP file
    pos = 0
    from := 0
    to := m.cnt
    // fmt.Printf("MAP2.INDEX. fh=%x from=%d to=%v len(data)=%v\n", fh, from, to, len(m.data))
    for {
        pos = (from + to) >> 1
        //fmt.Printf("%v <%v> %v\n", from, pos, to)
        dp := pos * 10
        cfh := m.data[dp:dp+10]
        cmp := bytes.Compare(fh[:], cfh)
        if cmp == 0 {
            return
        }
        if pos == from {
            return
        }
        if cmp > 0 {
            from = pos
        } else {
            to = pos
        }
        if from == to {
            return
        }
    }
    return
}

func (m *MAP2) Offset(fh bphash) (offset int) {
    block_index := m.Index(fh)
    m.imap.Read(block_index)
    return m.imap.Offset(fh)
}

/*
func (m *MAP2) Offset(fh bphash) (offset int) {
    block_index := m.Index(fh)
    m.imap.Read(block_index)
    expected_start := m.imap.data[0:10]
    if bytes.Compare(fh[:], expected_start) == 0 {
        return m.imap.Offset(fh)
    }
    // MAP/MAP2 FILES OUT OF SYNC !!!
    m.Read()
    block_index = m.Index(fh)
    m.imap.Read(block_index)
    expected_start = m.imap.data[0:10]
    if bytes.Compare(fh[:], expected_start) == 0 {
        return m.imap.Offset(fh)
    }
    // MAP/MAP2 FILES STILL OUT OF SYNC !!!
    return 1
}
*/