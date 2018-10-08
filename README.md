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
    
