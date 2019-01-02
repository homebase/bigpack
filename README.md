# bigpack

Blazing Fast Petabyte Scale Static Web Server + Tools.

Serve Billion Files from an Indexed, Compressed and Deduplicated Archive.

* Pack lots of files in directory/sub-directories into several files (data file + indexes)
* Deduplicate File Contents
* compress files (gzdeflate is used internally (when appropriate))
  better compression level than tar.gz
* Optionally shards files (up to 65536 shards) (todo)
* Serve specific files via http fast
  * 20K/sec random queries, 200MB/sec effective network traffic (developer PC, 400MB bigpack archive)
  * Compact and Efficient Indexes
  * Limiting factor is your Network/SSD/KernelTCPStack speed
    filesystem overhead completely eliminated
  * 2-level-index needs only ~3MB RAM for 100M files, ~20MB for 1B archived files
    one 8K index read per file
  * One level index needs 1.5GB RAM for 100M files (no extra reads, only ~9 memory reads per request)
* Limits:
    * Max Archive(Shard) Size: `256TB (2**48)`
    * Max Shards: `65536 (2**16)`
    * Max Stored Data Size: `16,777PB (petabytes)` || `16.7EB (exabytes)`
    * Max Archived File Size: `1TB (2**40)`
    * Max Archived File Count: `4.3 Billion files (2**32)`
    * File-Content-Hash: `80bit`
    * File-Name-Hash: `80bit`
* Recommendations:
    * use SSD (hard disks can't handle random access)
    * defrag your data-file: e4defrag, xfs_fsr
    * split data in shards, keep shards small: 20GB - 100GB
* MIT licensed Open Source Project
* Can use filesystem or raw-partition for data storage

# Use Cases:
* Export whole/part-of web site as static pages, serve it
* Use as a static pages server for your project, deploy/rollback your files in a second
* backup solution with deduplication - keep history, cheap snapshots for your project
* Combine OpenStreetMap map-tiles in one file, avoid filesystem overhead, serve them super fast
* use nginx as front-end http2 server


## Golang BigPack Server (go/bigpack-server)
* high performance bigpack server written in golang
* 20K/sec random requests on 350GB archive
* 200MB/sec http traffic served on average developer's computer

# Installation
## Golang web server installation
provides web server only
```
cd /usr/local/bin
sudo wget https://github.com/homebase/bigpack/releases/download/1.0.0/bigpack-server
sudo chmod +x bigpack-server
sudo setcap 'cap_net_bind_service=+ep' /usr/local/bin/bigpack-server
```
1. last command allows bigpack-server to listen on port 80 from unprivileged user
2. check https://github.com/homebase/bigpack/releases for latest release version

### Golang web sever usage
```
> cd directory_with_bigpack_archive
> bigpack-server --help
Usage of bigpack-server:
  -listen string
        listen ip and port (default "127.0.0.1:8081")
> GOMAXPROCS=8 bigpack-server --listen "my_public_ip:80"
```

## Bigpack PHP 
Provides cli(command-line) tools:
* archiving, extracting
* web server
* rsync wrapper and other utilities

*Pre-requisites*: `git`, `php7.2`, `php-pecl-apcu` (needed for "bigpack server")
```
[~]$ cd /usr/local/src/
[src]$ sudo mkdir bigpack
[src]$ sudo chown $USER bigpack
[src]$ git clone https://github.com/homebase/bigpack.git 
Initialized empty Git repository in /usr/local/src/bigpack/.git/
...
Receiving objects: 100% (323/323), 5.38 MiB, done.
Resolving deltas: 100% (194/194), done.
[src]$ cd /usr/local/bin
[bin]$ sudo ln -s ../src/bigpack/php/bigpack
[bin]$ bigpack
hb\bigpack\Cli::help
File Compressor with Deduplication.
...
```


# Usage example
```
[~]$ cd 
[~]$ mkdir tmp
[~]$ cd tmp
[tmp]$ ll
total 0
[tmp]$ cp /etc/passwd /etc/hosts /etc/resolv.conf .
[tmp]$ bigpack init
Starting Packer. Use "kill 882431" to safe-stop process
{"stats":{"files":3,"file-size":792,"files-compression-skipped":2,"files-compressed":1}}

DONE
MAP: 3 items 
MAP2: 1 items
[tmp]$ bigpack list
[tmp]$ cp /etc/redhat-release /etc/centos-release /etc/init.conf /etc/php.ini .
[tmp]$ bigpack add
Starting Packer. Use "kill 883847" to safe-stop process
{"stats":{"files":3,"file-size":19701,"files-compression-skipped":3,"known-files":3,"files-compressed":1,"dedup-files":1,"dedup-size":27}}

DONE
MAP: 7 items 
MAP2: 1 items
[tmp]$ bigpack server &
Starting bigpack php-web server. http://localhost:8080

[~]# curl -I  localhost:8080/php.ini
HTTP/1.1 200 OK
Host: localhost:8080
Date: Wed, 26 Dec 2018 20:44:56 +0000
Connection: close
X-Powered-By: PHP/7.2.13
Content-Encoding: deflate
Content-type: text/html;charset=UTF-8
Etag: e0ac131172774a949e70

[~]# curl --compressed  localhost:8080/php.ini | head -10
;;;;;;;;;;;;;;;;;;;
; About php.ini   ;
;;;;;;;;;;;;;;;;;;;
; PHP's initialization file, generally called php.ini, is responsible for
; configuring many of the aspects of PHP's behavior.
....
```

# Cli Tools
* run `bigpack help` to see all commands
* run `bigpack help CommandName` to see command help / options

## bigpack init
* compress all files in directory/subdirectories
* build indexes

## bigpack add
* adds new files to BigPack
* ignores existing files
* rebuild indexes

## bigpack list
* list files in archive (latest revision, or all revisions)

## bigpack extract
* Extract packed files
* Extract specific file, file+revision from archive

## bigpack deleteContent `[--undelete]`
* Mark specific Content as Deleted/Undeleted (e.g. DMCA request)
* Important: actual content is NOT deleted. use --undelete to undelete
* web server will return HTTP 410 GONE for files

## bigpack generateIndex
* generate `index.html` with links to all files stored in bigpack

## bigpack removeArchived
* remove alredy archived files (file last-modification-check performed)

## bigpack sync $remote
 * SAFE rsync Bigpack, remote web server will be automatically reloaded

## bigpack server (php)
* Start a webserver, that serves files from  BigPack
* even this server is more than good enough when placed behind nginx
* run single-thread PHP server
* minimal memory requirements
* Options:
    * --port   - tcp port number - default 8080
    * --host   - hostname listen to
* supports ETAG, EXPIRES
* compressed(gzdeflate) files served as compressed

# See Also
* [License - MIT](LICENSE)
* [Internal Notes](INTERNALS.md)
* [Todo](TODO.md)
