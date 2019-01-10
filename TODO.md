# Ideas / TODO:

## Switch to 4K blocks for MAP2
   memory-wise we'll use 2x more RAM, but MAP2 indexes are small! anyway
   even most ssd have 8k blocks - test this idea
   @see http://codecapsule.com/2014/02/12/coding-for-ssds-part-2-architecture-of-an-ssd-and-benchmarking/
   EZ to implement, just reindex (map/map2) exsiting archives

```
 ☐ BigPack and Directories
   BigPack.index: stored directories should ends with "/"
   extract should change permissions for existing, create new ones on extract
    ☐ bp: options to ignore dirs

 ☐ minor bug: addFromFilelist
   "incrorrectly created" file lists adds direcrtories as files
   BigPack.index: stored directories should ends with "/"

 ☐ bp-php: simplify code: split Advanced Methods into Traits (or inheritance)

 ☐ bp-go: read option files
    https://stackoverflow.com/questions/40022861/parsing-values-from-property-file-in-golang

 ☐ bp-go:
   ☐ Update memory indexes when Bigpack files changed
      bigpack sync
     ReRead map2 files when Data Files Out of sync
     run once: -
     var once sync.Once // guards initMime
     func initMime() { .... }
     once.Do(initMime)
```

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
*  ./BigPack/{5bit-[0-9A-Z]}/{3bit-hex}.data    -- 256 shards
*  ./BigPack/{5bit-[0-9A-Z]}/{4bit-hex}/{3bit-hex}.data    -- 4096 shards

## In-Memory File-Content Caching - Most used file caching
^^ IMO should be implemented as a standalone caching proxy (nginx / varnish)
* implement file-hash request watcher
* build list of most requested files along with size in sectors
* cache most used files using saved-disk-sector-reads as a measurment

## bigpack purge (todo)
* removes OLD file contents
* rebuild indexes
* optionally specify how many revisions you want to retain
* add as "bigpack addFromArchive --latest"



