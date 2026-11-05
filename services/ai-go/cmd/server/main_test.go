package main

import (
	"context"
	"encoding/pem"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"
)

func TestGracefulShutdownWaitsForActiveRequestAfterListenerCloses(t *testing.T) {
	entered, release, drained := make(chan struct{}), make(chan struct{}), make(chan struct{})
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		close(entered)
		<-release
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()
	var releaseOnce sync.Once
	finish := func() { releaseOnce.Do(func() { close(release) }) }
	defer finish()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := gracefulShutdown(ctx, server.Config, func() { close(drained) })
	responseDone := make(chan error, 1)
	go func() {
		response, err := server.Client().Get(server.URL)
		if err == nil {
			response.Body.Close()
		}
		responseDone <- err
	}()
	select {
	case <-entered:
	case <-time.After(5 * time.Second):
		t.Fatal("request did not enter handler")
	}
	cancel()
	<-drained
	select {
	case <-done:
		t.Fatal("shutdown returned while request was active")
	default:
	}
	finish()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("shutdown did not finish")
	}
	if err := <-responseDone; err != nil {
		t.Fatal(err)
	}
}

func TestHealthcheckUsesConfiguredPortAndVerifiesTLS(t *testing.T) {
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/readyz" {
			t.Error("unexpected health path")
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()
	cert := filepath.Join(t.TempDir(), "health.pem")
	if err := os.WriteFile(cert, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: server.Certificate().Raw}), 0600); err != nil {
		t.Fatal(err)
	}
	address := server.Listener.Addr().String()
	if err := healthcheck(address, cert); err != nil {
		t.Fatal(err)
	}
	if err := healthcheck(address, ""); err == nil {
		t.Fatal("TLS service accepted as plaintext health")
	}
	if err := healthcheck(address, filepath.Join(t.TempDir(), "missing")); err == nil {
		t.Fatal("missing certificate accepted")
	}
}

func TestHealthcheckFailsClosedOnNotReady(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(503) }))
	defer server.Close()
	if err := healthcheck(server.Listener.Addr().String(), ""); err == nil {
		t.Fatal("unready service accepted")
	}
}
