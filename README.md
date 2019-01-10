# bigpack

Blazing Fast Petabyte Scale Static Web Server + Tools.

Serve Billion Files from an Indexed, Compressed and Deduplicated Archive.

Think of `tar + gzip + dedup + web-server` - alike combination on steroids

* Pack lots of files in directory/sub-directories into several files (data file + indexes)
* Deduplicate File Contents
* Optionally Compress Files, better compression level than tar.gz
* Fast Web Server
  * 20K/sec random queries, 200MByte/sec effective network traffic (10Gbe network, developer PC, 400GB archive)
  * Low Memory Usage:
    * 2-level-index needs only ~3MB RAM for 100M files, ~20MB for billion archived files
* Compact and Efficient Indexes
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
* MIT licensed Open Source Project
* Can use filesystem or raw-partition for data storage

# Use Cases:
* Export whole/part-of web site as static pages, serve it
* Use as a static pages server for your project, deploy/rollback your files in a second
* backup solution with deduplication - keep history, cheap snapshots for your project
* Combine OpenStreetMap map-tiles in one file, avoid filesystem overhead, serve them super fast
* use nginx as front-end http2 server

# Web Server Installation
```
* high performance bigpack server written in golang
* 20K/sec random requests on 400GB archive
* 200MB/sec http traffic served on developer's computer (10Gbe network)
```
cd /usr/local/bin
# check https://github.com/homebase/bigpack/releases for latest release
sudo wget https://github.com/homebase/bigpack/releases/download/1.0.0/bigpack-server
sudo chmod +x bigpack-server
# allow to listen on port 80 for unprivileged user
sudo setcap 'cap_net_bind_service=+ep' /usr/local/bin/bigpack-server
```

### Usage:
```
$ cd directory_with_bigpack_archive
$ bigpack-server --listen "my_public_ip:80"
```

# Command Line Tools Installation
*Pre-requisites*: `git`, `php7.2`, `php-pecl-apcu` (needed for "bigpack server")

```
cd /usr/local/src/
sudo mkdir bigpack
sudo chown $USER bigpack
git clone https://github.com/homebase/bigpack.git
cd /usr/local/bin
sudo ln -s ../src/bigpack/php/bigpack
```

Centos/RHEL prerequisite installation
```
# centos6/rhel6
sudo yum -y install http://rpms.remirepo.net/enterprise/remi-release-6.rpm git
# centos7/rhel 7
sudo yum -y install http://rpms.remirepo.net/enterprise/remi-release-7.rpm git
sudo yum -y --enablerepo remi-php72 install php php-pecl-apcu
```


### Usage
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

## bigpack help
* *all* commands overview
* `bigpack help CommandName` - detailed help

## bigpack init
* compress all files in directory/subdirectories
* build indexes

## bigpack add
* adds *new files* to BigPack
* ignores existing files
* rebuild indexes

## bigpack list
* list files in archive (latest revision, or all revisions)

## bigpack extract
* Extract packed files
* Extract specific file, file+revision from archive
* see extractMap2 command for fast extraction

## bigpack deleteContent `[--undelete]`
* Mark specific Content as Deleted/Undeleted (e.g. DMCA request)
* Important: actual content is NOT deleted
* HTTP "410 GONE" Code returned for deleted files by web-server

## bigpack removeArchived
* remove alredy archived files (file last-modification-check performed)

## bigpack sync $remote
 * SAFE rsync Bigpack, remote bigpack-web server will be automatically reloaded

## bigpack server (php)
* Start a webserver, that serves files from  BigPack
* even this server is more than good enough when placed behind nginx
* run single-thread PHP server
* minimal memory requirements
* supports ETAG, EXPIRES
* compressed(gzdeflate) files served as compressed
* Options:
    * --port   - tcp port number - default 8080
    * --host   - ip/hostname listen to

## bigpack ...  (more commands)
* `generateIndex` - generate `index.html` with links to all files stored in bigpack
* `merge` - merge archives
* `split` - split archive
* `replaceFiles`, removeFiles, 
* `addFromArchive` - add files from another archive
* `check` - check validity of Index and Data files
* `extract --check` - check archive for data corruption
* `extractMap2` - super-fast extract file from huge archives
* `help` - see EVEN more commands

# See Also
* [License - MIT](LICENSE)
* [Internal Notes](INTERNALS.md)
* [Todo](TODO.md)
