package bigpack

import (
    "fmt"
    "net/http"
)

type (

    Server struct {
        map2 MAP2
    }

)


// init should fail on any errors
func (s *Server) Init() {
    s.map2 = MAP2 {}
    s.map2.Read()
    //fmt.Printf("* m.map2.data size %d\n", len(m.map2.data))
}


func (s Server) InitMime() {
/*
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
 */
}

func (s *Server) Serve(w http.ResponseWriter, r *http.Request) {
    w.Header().Set("Content-Type", "text/html")
    uri := r.URL.Path
    if uri[len(uri)-1:] == "/" {
        uri = "/index.html";
    }
    uri = uri[1:]  // cut leading "/"
    fh := bpHash(uri)
    fmt.Fprintf(w, "Path is: %s<br>", uri)
    fmt.Fprintf(w, "FH is: %x<br>", fh)
    offset := s.map2.Offset(fh)
    // offset := s.map2.Index(fh)
    fmt.Fprintf(w, "Offset is: %v<br>", offset)
    fmt.Fprintf(w, "<h1>BigPack golang server</h1>")
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

