package main

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"errors"
	"flowmv/ai-go/internal/ai"
	"flowmv/ai-go/internal/api"
	"flowmv/ai-go/internal/providers"
	"flowmv/ai-go/internal/security"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"
)

func main() {
	if len(os.Args) == 2 && os.Args[1] == "--healthcheck" {
		if healthcheck(os.Getenv("AI_LISTEN"), os.Getenv("AI_TLS_CERT")) != nil {
			os.Exit(1)
		}
		return
	}
	logger := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	key, e := hex.DecodeString(os.Getenv("AI_SERVICE_KEY"))
	if e != nil || len(key) != 32 {
		logger.Error("service_configuration_invalid")
		os.Exit(1)
	}
	address := os.Getenv("AI_LISTEN")
	if address == "" {
		address = "127.0.0.1:8020"
	}
	host, _, e := net.SplitHostPort(address)
	container := os.Getenv("AI_PRIVATE_CONTAINER") == "true"
	if e != nil || (!container && host != "127.0.0.1" && host != "::1") {
		logger.Error("private_listen_required")
		os.Exit(1)
	}
	simulated := os.Getenv("AI_SIMULATED") == "true"
	installation, installationError := hex.DecodeString(os.Getenv("AI_DEMO_INSTALLATION"))
	if simulated && (installationError != nil || len(installation) != 32 || os.Getenv("AI_DEMO_DATABASE") != "flowmv_phase10_demo") {
		logger.Error("simulation_guard_required")
		os.Exit(1)
	}
	service := &ai.Service{Providers: map[string]ai.Provider{}, Simulated: simulated}
	for _, name := range []string{"openai", "gemini"} {
		if simulated {
			service.Providers[name] = ai.Simulated{}
		} else {
			service.Providers[name] = providers.New(name)
		}
	}
	handler := api.New(security.New(key), service, logger)
	server := &http.Server{Addr: address, Handler: handler, ReadHeaderTimeout: 3 * time.Second, ReadTimeout: 5 * time.Second, WriteTimeout: 30 * time.Second, IdleTimeout: 30 * time.Second, MaxHeaderBytes: 8192}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	shutdownDone := gracefulShutdown(ctx, server, handler.Drain)
	cert, tlsKey := os.Getenv("AI_TLS_CERT"), os.Getenv("AI_TLS_KEY")
	if (cert == "") != (tlsKey == "") {
		logger.Error("tls_configuration_invalid")
		os.Exit(1)
	}
	server.TLSConfig = &tls.Config{MinVersion: tls.VersionTLS12}
	logger.Info("ai_service_started", "simulated", simulated)
	if cert != "" && tlsKey != "" {
		e = server.ListenAndServeTLS(cert, tlsKey)
	} else {
		e = server.ListenAndServe()
	}
	if e != nil && e != http.ErrServerClosed {
		logger.Error("ai_service_stopped")
		os.Exit(1)
	}
	if e == http.ErrServerClosed {
		<-shutdownDone
	}
}

func gracefulShutdown(ctx context.Context, server *http.Server, drain func()) <-chan struct{} {
	done := make(chan struct{})
	go func() {
		defer close(done)
		<-ctx.Done()
		drain()
		shutdown, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		if server.Shutdown(shutdown) != nil {
			_ = server.Close()
		}
	}()
	return done
}

// Health uses the configured port and verifies TLS, including the loopback SAN.
func healthcheck(address, certFile string) error {
	port := "8020"
	if address != "" {
		_, parsed, err := net.SplitHostPort(address)
		if err != nil {
			return err
		}
		port = parsed
	}
	scheme := "http"
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.Proxy = nil
	if certFile != "" {
		certificate, err := os.ReadFile(certFile)
		if err != nil {
			return err
		}
		roots, err := x509.SystemCertPool()
		if err != nil {
			roots = x509.NewCertPool()
		}
		if !roots.AppendCertsFromPEM(certificate) {
			return errors.New("invalid health certificate")
		}
		transport.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: roots}
		scheme = "https"
	}
	defer transport.CloseIdleConnections()
	client := &http.Client{Timeout: 2 * time.Second, Transport: transport, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	response, err := client.Get(scheme + "://127.0.0.1:" + port + "/readyz")
	if err != nil {
		return err
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return errors.New("not ready")
	}
	return nil
}
