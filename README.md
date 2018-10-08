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
* MIT licensed Open Source Project
* Can use filesystem file or raw-partition as a data storage

# Use Case:
* Combine OpenStreetMap map-tiles in one(several) file, avoid filesystem overhead, serve them super fast
* Export whole web site as static pages, serve it
* use nginx as front-end http2 server (plus you can add your header/footer, whatever)

# Cli Tools

## bigpack init
* compress all files in directory/subdirectories
* removes added files
* build indexes

## bigpack add
* adds new files to BigPack 
* removes added files
* ignores existing files
* rebuild indexes

## bigpack update
* adds new file contents to BigPack (old content kept intact)
* removes added files
* no-longer relevant filename to content mapping stored in BigPack.deleted file
* rebuild indexes

## bigpack extract
* Exract packed files

## bigpack purge
* removes unused file contents, clean up BigPack.deleted
* rebuild indexes

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
