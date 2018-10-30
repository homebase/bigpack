# bigpack

Blazing Fast Petabyte Scale Static Web Server + Tools.
Serve Billion Files from an Indexed, Compressed and Deduplicated Archive.

* Pack lots of files in directory/sub-directories into several files (data file + indexes)
* Deduplicate File Contents
* compress files (gzip compression is used internally (when appropriate))
  same or better compression than tar.gz
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
    * Max Stored Data Size: `16,777PB` || `16.7EB`
    * Archived File Size: `1TB (2**40)`
    * Archived File Count: `4.3B (2**32)`
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

## bigpack init
* compress all files in directory/subdirectories
* removes added files (optionally)
* build indexes

## bigpack add
* adds new files to BigPack
* removes added files (optionally)
* ignores existing files
* rebuild indexes

## bigpack update
* adds new file contents to BigPack (old content kept intact)
* removes added files (optionally)
* no-longer relevant filename to content mapping stored in BigPack.deleted file
* rebuild indexes

## bigpack list
* list files in archive (latest revision, or all revisions)

## bigpack extract
* Extract packed files
* Extract specific file, file+revision from archive

## bigpack delete
* Remove specific files from index
* no-longer relevant filename to content mapping stored in BigPack.deleted file
* rebuild indexes

## bigpack purge
* removes unused file contents, clean up BigPack.deleted
* rebuild indexes
* optionally specify how many revisions you want to retain

## bigpack merge
 * merge two or more bigpacks
 * rebuild indexes

## bigpack-sync $remote
 * rsync changes + (optionally) reload remote web-service

# BigPack Server

## "bigpack-server $dir" command (golang)
* Start a webserver, that serves files from  BigPack files, or from filesystem
    * deep directories with BigPack are checked first
    * only then filesystem.
    * Implementation language: golang
    * Bigpack files have priority over filesystem.
* Options:
    * --port=port  - tcp port number - default 8080
    * --socket=filename   - unix socket name


# Internals

## BigPack Files
* BigPack.data  - File Contents
* BigPack.index - Map Path/File Names to Content (text file, tab separated format)
* BigPack.deleted - no-longer relevant filename to content mappings (same format as index)
* BigPack.map  - binary index. hash(filename) => datafile_offset mapping (sorted by hash)
* BigPack.map2 - binary index of index - kept in memory for web-service
                 ordered list of every 512th hash(filename) from *map file
* BigPack.options - options in key=value format
    sharding=on/off ; shards=## ;data-file-alignment=0,2,4,8,16 ; compression ....; expires-tags; files-not-to-gzip, ...

When sharding is enabled files located in BigPack directory + subdirectories.
Names are "$Shard.*"
Example: ./BigPack/{5bit}/{3bit}_$data    -- 256 shards

# Ideas / TODO:

## Speed up first 16 lookup for binary search
in-memory-only index of map2. index of index of index.
array[uint16 hash_prefix] => index of first-prefix-entry in map2 array.

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
