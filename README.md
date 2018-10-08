# bigpack

Blazing Fast Static Web Server + Tools. 
Supports Compression, Deduplication, Petabyte Scale. 
Serve Millions Files from an Indexed Archive.

* Pack lots of files in directory/sub-directories into several files (data file + indexes)
* Dedup Files
* Optionally compress files
* Optionally shards files (up to 65536 shards)
* Serve specific files via http fast
* Can store up to 1 billion files w/o probability of content-hash collisions (80bit hashes used)
    * probability of collision
    *  for 1 billion items stored: ~4E-7 (odds of winning a 6/49 lottery)
    *  for 1 million items stored: ~4E-13 (odds of a meteor landing on your house)
* Can store up to 256TB (1PB with 4-byte datafile alignment)
* Shard limit: 1TB (4TB with 4-byte datafile alignment)
* MIT licensed Open Source Project
* Can use filesystem or raw-partition for data storage
    * indexes are pretty small and should be kept in filesystem

# Use Case:
* Combine OpenStreetMap map-tiles in one(several) file, avoid filesystem overhead, serve them super fast
* Export whole web site as static pages, serve it
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
 * merge two or several bigpacks
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

## Generated Files
* BigPack.data  - File Contents
* BigPack.index - Map Path/File Names to Content (text file, tab separated format)
* BigPack.deleted - no-longer relevant filename to content mappings (same format as index)
* BigPack.map - binary index. hash(filename) => datafile_offset mapping (sorted by hash)
* BigPack.map2 - binary index of index - kept in memory for web-service.
* BigPack.options - options in key=value format
    sharding=on/off ; shards=## ;data-file-alignment=0,2,4,8,16 ; compression ....

When sharding is enabled files located in BigPack directory + subdirectories. Names are "$Shard.*"
Ex. BigPack/{5bit}/{3bit}_$data
