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


