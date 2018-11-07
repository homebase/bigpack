/*
   BigPack Web Server

   Serve packed files from bigpack archive


    TODO:
    > make package
    > graceful data reload (load new index2) - (no lost requests)

*/

package main

import (
	"bigpack"
	//"bufio"
	//"bytes"
	//"compress/gzip"
	"flag"
	"fmt"
	"io/ioutil"
	//"net"
	"os"
	"runtime"
	//"strconv"
	//"strings"
	//"sync/atomic"
	"net/http"
	"os/signal"
	"time"
)

var (
	g_TEST         int8   // 1 - test mode
	g_MemAllocated uint64 // memory allocated tracking

	g_TimeStarted   time.Time // time since last Init()
	g_RequestServed int       = 0
	g_Server        bigpack.Server
)

func RootHandler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "text/html")
	g_RequestServed++
	fmt.Fprintf(w, "Path is: "+r.URL.Path)
	fmt.Fprintf(w, "<h1>BigPack golang server</h1>")
}

func ReloadHandler(w http.ResponseWriter, r *http.Request) {
	fmt.Println("* reload")
	Init()
	fmt.Fprintln(w, "done")
}

func StatusHandler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "text/html")
	// fmt.Println("* status")
	fmt.Fprintf(w, "Initialized at %v<br>\n", g_TimeStarted)
	fmt.Fprintln(w, "Requests Served: ")
	fmt.Fprintln(w, g_RequestServed)
}

// write PID file
func Prepare(pid_file *string) {
	// write pid file
	pid := fmt.Sprintf("%d", os.Getpid())
	err := ioutil.WriteFile(*pid_file, []byte(pid), 0644)
	if err != nil {
		panic(err)
	}
}

// report allocated memory to STDOUT
func MemReport(event string) {
	memStats := &runtime.MemStats{}
	runtime.ReadMemStats(memStats)
	diff := int64(memStats.Alloc) - int64(g_MemAllocated)
	if event != "" {
		fmt.Print(event + " ")
	}
	fmt.Printf(" - MEM(MB) allocated:%.1f diff:%.1f\n", float64(memStats.Alloc)/0x100000, float64(diff)/0x100000)
	g_MemAllocated = memStats.Alloc
}

func Init() {
	MemReport("Bigpack-Server Init")
	g_Server = bigpack.Server{} //  { map: "", cnt: 0, }
	g_Server.Init()
	g_TimeStarted = time.Now()
	MemReport("Init Complete")
}

func main() {

	pid_file := flag.String("pid", "/run/bigpack/server.pid", "pidfile location")
	// socketname := flag.String("socket", "/run/bigpack/server.sock", "unix socket location")
	port := flag.String("port", "127.0.0.1:8081", "listen port")
	// port := flag.Int("port", 6060, "tcp port for to bind-to, 0 - no tcp support")
	test := flag.Int("test", 0, "DEBUG-ONLY turn on test mode. supported value: 1")
	flag.Parse()

	if *test == 1 {
		g_TEST = 1
	}

	Prepare(pid_file) // check permisssions, dir, structure, write pid file
	defer os.Remove(*pid_file)

	// support kill
	go func() {
		c := make(chan os.Signal, 1)
		signal.Notify(c, os.Interrupt)
		sig := <-c
		fmt.Printf("\nexiting.. %v", sig)
		os.Remove(*pid_file)
		os.Exit(1)
	}()

	Init()

	fmt.Printf("BigPack Server Started, listening at %s\n", *port)
	defer fmt.Println("*** Server Finished")

	http.HandleFunc("/", RootHandler)
	http.HandleFunc("/status", StatusHandler)
	http.HandleFunc("/reload", ReloadHandler)
	http.ListenAndServe((*port), nil)

	for {
		// check for exit flag
		time.Sleep(time.Second)
	}

}
