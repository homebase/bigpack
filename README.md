# bigpack

Blazing Fast Petabyte Scale Static Web Server + Tools.
Serve Billion Files from an Indexed, Compressed and Deduplicated Archive.

* Pack lots of files in directory/sub-directories into several files (data file + indexes)
* Deduplicate File Contents
* compress files (gzdeflate is used internally (when appropriate))
  better compression level than tar.gz
* Optionally shards files (up to 65536 shards) (todo)
* Serve specific files via http fast
  * Limiting factor is your Network/SSD/KernelTCPStack speed
    filesystem overhead completely eliminated
  * 2-level-index approach needs only ~3MB RAM for 100M files, ~20MB for 1B archived files
    one extra 8K (ssd sector size) read per file
  * One level index needs 1.5GB RAM for 100M files (no extra reads, only ~9 memory reads per request)
* Can store up to 4 billion files - super low probability of content-hash collisions (80bit hashes used)
    * probability of collision
    *  for 1 billion items stored: ~4E-7 (odds of winning a 6/49 lottery)
    *  for 1 million items stored: ~4E-13 (odds of a meteor landing on your house)
* Limits:
    * Archive(Shard) Size: `256TB (2**48)`
    * Max Shards: `65536 (2**16)`
    * Max Stored Data Size: `16,777PB (petabytes)` || `16.7EB (exabytes)`
    * Archived File Max-Size: `1TB (2**40)`
    * Archived File Count: `4.3 Billion files (2**32)`
    * File-Content-Hash: `80bit`
    * File-Name-Hash: `80bit`
    * recommendation: split data in shards, keep shards relatively small - ~10 - 20GB
* MIT licensed Open Source Project
* Can use filesystem or raw-partition for data storage

# Use Cases:
* Combine OpenStreetMap map-tiles in one(several) file, avoid filesystem overhead, serve them super fast
* Export whole/part-of web site as static pages, serve it
* Use a separate static pages server for your project, deploy/rollback your files in a second
* backup storage system with deduplication - keep history
* make cheap snapshots for your directories
* use nginx as front-end http2 server (plus you can add your header/footer, whatever)

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

## bigpack update (TODO)
* adds new file contents to BigPack (old content kept intact)
* removes added files (optionally)
* no-longer relevant filename to content mapping stored in BigPack.deleted file
* rebuild indexes

## bigpack list
* list files in archive (latest revision, or all revisions)

## bigpack extract
* Extract packed files
* Extract specific file, file+revision from archive

## bigpack deleteContent `[--undelete]`
* Mark specific Content as Deleted/Undeleted (e.g. DMCA request)
* web server will return HTTP 410 GONE for files

## bigpack generateIndex
* generate `index.html` with links to all files stored in bigpack

## bigpack removeArchived
* remove alredy archived files (file last-modification-check performed)

## bigpack purge (TODO)
* removes unused file contents, clean up BigPack.deleted
* rebuild indexes
* optionally specify how many revisions you want to retain

## bigpack merge
 * merge two or more bigpacks
 * rebuild indexes

## bigpack-sync $remote
 * SAFE rsync Bigpack, remote web server will be automatically reloaded

## bigpack server
* Start a webserver, that serves files from  BigPack
* even this server is good enough when placed behind nginx
* run single-thread PHP server
* minimal memory requirements
* Options:
    * --port   - tcp port number - default 8080
    * --host   - hostname listen to
* supports ETAG, EXPIRES
* compressed(gzdeflate) files served as compressed

## BigPack Server (GOLANG)
* bigpack-server-go

# Internals

## BigPack Files
* BigPack.data  - Data Contents. 16 byte prefix with 5byte length field, then file-data (data may be compressed)
* BigPack.index - Map Path/File Names to Content (text file, tab separated format)
* BigPack.map  - binary index. hash(filename) => datafile_offset mapping (sorted by hash)
* BigPack.map2 - binary index of index - kept in memory for web-service. File is GZIPPED !!
                 ordered list of every 512th hash(filename) from map file.
                 NN-index of an item = NN of 8k block in `*.map` file
* BigPack.options - options in key=value format
    sharding=on/off ; shards=## ;data-file-alignment=0,2,4,8,16 ; compression ....; expires-tags; files-not-to-gzip, ...
* BigPack.deleted - no-longer relevant filename to content mappings (same format as index)

When sharding is enabled files located in BigPack directory + subdirectories.
Names are "$Shard.*"
Example: ./BigPack/{5bit}/{3bit}_$data    -- 256 shards

# Ideas / TODO:

## Support for "etag" header
* Keep content-hash in data file - serve it as ETAG !!
* "304" non modified response

## Support for "expires" header
* configured in BigPack.options

## In-Memory File-Content Caching - Most used file caching
^^ IMO should be implemented as a standalone caching proxy
* implement file-hash request watcher
* build list of most requested files along with size in sectors
* cache most used files using saved-disk-sector-reads as a measurment
