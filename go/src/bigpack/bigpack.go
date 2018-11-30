package bigpack

import (
    "net/http"
    "encoding/hex"
    "fmt"
    "strings"
    "path/filepath"
    "mime"
    "log"
)

type (

    Server struct {
        map2 MAP2
    }

)

const (
    // BROWSER_CACHE_MAX_AGE = "max-age=2592000" // 30 days
    BROWSER_CACHE_MAX_AGE = "max-age=604800" // 7 days
)

// init should fail on any errors
func (s *Server) Init() {
    s.map2 = MAP2 {}
    s.map2.Read()
    //fmt.Printf("* m.map2.data size %d\n", len(m.map2.data))
}

func (s *Server) Serve(w http.ResponseWriter, r *http.Request) {
    uri := r.URL.Path
    if uri[len(uri)-1:] == "/" {
        uri += "index.html";
    }
    uri = uri[1:] // cut leading "/"
    // log.Printf("uri: %v", uri)
    fh := bpHash(uri)

    offset := s.map2.Offset(fh)
    if offset == 0 {
        w.WriteHeader(404)
        fmt.Fprintf(w, "404 - %v not found. fh=%x\n", uri, fh)
        w.Write([]byte(""))
        return
    }
    if offset == 1 {
        w.WriteHeader(500)
        w.Write([]byte("500 - Server Error - Index out of sync"))
        log.Printf("Index out of sync")   // USE ONCE - do not spam logs !!
        return
    }

    // READING FILE
    // todo:
    //   optimize - split read in two - we do not need to read whole file for If-None-Match
    //   we also do not need to read all file into memory, just a chunks of it
    //
    data, data_hash, flags := ReadDataOffset(offset)
    if flags & FLAG_DELETED > 0 {
        w.WriteHeader(http.StatusGone)
        w.Write([]byte("410 - Gone"))
        return
    }
    ask_etag := strings.Trim(r.Header.Get("If-None-Match"), "\"")
    etag := hex.EncodeToString(data_hash[:])
    if etag == ask_etag {
        w.WriteHeader(http.StatusNotModified)
        w.Write([]byte("\n"))
        return
    }

    // Serving File - Headers
    // mime-type
    ext := filepath.Ext(uri)
    mime_type := mime.TypeByExtension(ext)
    w.Header().Set("Content-Type", mime_type)
    w.Header().Set("ETag", etag)
    w.Header().Set("Cache-Control", BROWSER_CACHE_MAX_AGE) // works same way as expires header
    if flags & FLAG_GZIP > 0 {
        w.Header().Set("Content-Encoding", "deflate");
    }

    // Serving File - Data
    w.Write(data)
}

/*
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
        //
        $ext_pos = strrpos($file, '.', 1);
        $ext = $ext_pos !== false ? substr($file, $ext_pos +1 ) : "html";
        $mime_type = $this->mime_types[$ext] ?? "text/html";
        header("Content-Type: $mime_type");
        //
        header("Etag: $etag");
        if ($this->expires_min)
            header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + ($this->expires_min * 60)));
        echo $data;
 */

