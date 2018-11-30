# Ideas / TODO:

## Sharding - TODO
* One Server Sharding:
    * URL(filename) >> choose shard >> serve data
    * spread data over several SSDs

* Two Level Sharding:
    * URL(filename) >> choose shard >> forward-to-server-with-shard >> serve-data
    * spread data over several Servers / several SSDs

When sharding is enabled files located in BigPack directory + subdirectories.
There are no reason to shard index, map, map2 files
Moreover you do not need `index` file on web-server at all

Example:
*  ./BigPack/{7bit-hex}.data    -- 128 shards
*  ./BigPack/{5bit-hex}/{3bit-hex}.data    -- 256 shards
*  ./BigPack/{5bit-hex}/{4bit-hex}/{3bit-hex}.data    -- 4096 shards

## In-Memory File-Content Caching - Most used file caching
^^ IMO should be implemented as a standalone caching proxy (nginx / varnish)
* implement file-hash request watcher
* build list of most requested files along with size in sectors
* cache most used files using saved-disk-sector-reads as a measurment

## bigpack update (todo)
* adds new file contents to BigPack (old content kept intact)
* removes added files (optionally)
* no-longer relevant filename to content mapping stored in BigPack.deleted file
* rebuild indexes

## bigpack purge (todo)
* removes unused file contents, clean up BigPack.deleted
* rebuild indexes
* optionally specify how many revisions you want to retain

## bigpack merge (todo)
 * merge two or more bigpacks
* rebuild indexes
