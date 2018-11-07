package bigpack

import (
    "io/ioutil"
    "fmt"
)

type (

    Server struct {
        data  string
        cnt int
    }

)


// init should fail on any errors
func (m Server) Init() {
    b, err := ioutil.ReadFile(FILE_MAP2)
    if err != nil {
        panic(err)
    }
    m.data = string(b)
    m.cnt = len(m.data) / 10
    fmt.Printf("%s Init. count=%d\n", FILE_MAP2, m.cnt)
}


func (m Server) InitMime() {
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

func (m Server) Serve(uri string) {
/*
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

}