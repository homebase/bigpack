# Internals

## Memory usage

* Web server RAM usage is minimal - ~ 5MB megabytes for 1.xTB archive.
* for ADD/Merge/Index operations on 1TB archive you need ~45GB RAM
```
# merge of 950GB + 320GB  archives, 45GB RAM used, ~90 minutes
  (6min-preparation, then actual merge, then 10min index(map/map2) building) - ssd-to-ssd, CPU:x5650-xeon
{"stats":{"files":53,766,381,files-size":353,767,966,196,"dedup-files":12,483,468,"dedup-size":13,375,990,928}}
DONE. 53,766,381 files added
MAP: 236,187,123 items                 << TOTAL FILES IN ARCHIVE - DISK-INDEX
MAP2:    461,303 items                 << MEMORY-INDEX of MAP. ~80bits-per item

Final archive:
1.3T  BigPack.data
 22G  BigPack.index
3.6G  BigPack.map
4.4M  BigPack.map2
```

## BigPack Files
* BigPack.data  - Data Contents. 16 byte prefix with 5byte length field, then file-data (data may be compressed)
* BigPack.index - Map Path/File Names to Content (text file, tab separated format)
* BigPack.map  - binary index. hash(filename) => datafile_offset mapping (sorted by hash)
* BigPack.map2 - binary index of index - kept in memory for web-service
                 ordered list of every 512th hash(filename) - from map file. (80bits per entry)
                 NN-index of an item = NN of 8k block in `*.map` file
* BigPack.options - options in key=value format
    sharding=on/off ; shards=## ;data-file-alignment=0,2,4,8,16 ; compression ....; expires-tags; files-not-to-gzip, ...


