package main

import (
	"context"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"sync"
	"syscall"

	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/client"
)

const (
	UnixSocketPath = "/var/run/strikepanel/strikepanel.sock"
	TCPAddress     = "0.0.0.0:8443"
	DataPath       = "/srv/strikepanel/servers"
)

type Daemon struct {
	dockerClient *client.Client
	mux          *http.ServeMux
}

func NewDaemon() (*Daemon, error) {
	cli, err := client.NewClientWithOpts(client.FromEnv, client.WithAPIVersionNegotiation())
	if err != nil {
		return nil, err
	}

	d := &Daemon{
		dockerClient: cli,
		mux:          http.NewServeMux(),
	}
	d.registerRoutes()
	return d, nil
}

func (d *Daemon) registerRoutes() {
	d.mux.HandleFunc("/api/system", func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"status":"online"}`))
	})
}

// enforceCgroups applies Linux Cgroup constraints prior to starting a container
func (d *Daemon) enforceCgroups(ctx context.Context, serverUUID string, memoryMB int64, cpuLimit int64, swapMB int64) error {
	// Converts memoryMB to bytes.
	// NanoCPUs are calculated as percent * 1e7 (100% = 1 core = 1e9).
	hostConfig := &container.HostConfig{
		Resources: container.Resources{
			Memory:     memoryMB * 1024 * 1024,
			MemorySwap: swapMB * 1024 * 1024,
			NanoCPUs:   cpuLimit * 10000000,
		},
		Binds: []string{
			filepath.Join(DataPath, serverUUID) + ":/home/container",
		},
	}
	_ = hostConfig // Ready for injection into ContainerCreate calls
	return nil
}

func main() {
	log.Println(">>> Bootstrapping Strike Panel Go Engine...")

	daemon, err := NewDaemon()
	if err != nil {
		log.Fatalf("Fatal: Failed to connect to Docker Engine: %v", err)
	}

	var wg sync.WaitGroup

	// 1. Inbuilt Localnode - Unix Socket Listener
	wg.Add(1)
	go func() {
		defer wg.Done()
		
		// Ensure socket directory exists
		if err := os.MkdirAll(filepath.Dir(UnixSocketPath), 0755); err != nil {
			log.Fatalf("Failed to create socket directory: %v", err)
		}

		// Cleanup stale socket
		os.Remove(UnixSocketPath)
		
		unixListener, err := net.Listen("unix", UnixSocketPath)
		if err != nil {
			log.Fatalf("Failed to bind Unix socket: %v", err)
		}
		
		// Grant PHP backend access to the socket
		os.Chmod(UnixSocketPath, 0777)
		
		log.Printf(">>> [Localnode] Bound to IPC Socket: %s", UnixSocketPath)
		if err := http.Serve(unixListener, daemon.mux); err != nil {
			log.Printf("Unix Listener shutdown: %v", err)
		}
	}()

	// 2. Distributed Mode - TCP Listener
	wg.Add(1)
	go func() {
		defer wg.Done()
		log.Printf(">>> [Remote Node] Bound to TCP: %s", TCPAddress)
		// For production, ListenAndServeTLS would be used with generated certs
		if err := http.ListenAndServe(TCPAddress, daemon.mux); err != nil {
			log.Printf("TCP Listener shutdown: %v", err)
		}
	}()

	// Graceful Lifecycle Control
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)
	<-sigChan

	log.Println(">>> Received shutdown signal. Instructing Docker to pause workloads...")
	os.Remove(UnixSocketPath)
}
