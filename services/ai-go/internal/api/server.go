package api

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flowmv/ai-go/internal/ai"
	"flowmv/ai-go/internal/contracts"
	"flowmv/ai-go/internal/document"
	"flowmv/ai-go/internal/security"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"
)

type Server struct {
	Auth     *security.Auth
	Service  *ai.Service
	Logger   *slog.Logger
	slots    chan struct{}
	mu       sync.Mutex
	window   time.Time
	requests int
	draining bool
}

func New(auth *security.Auth, service *ai.Service, logger *slog.Logger) *Server {
	return &Server{Auth: auth, Service: service, Logger: logger, slots: make(chan struct{}, 4)}
}
func (s *Server) Drain() { s.mu.Lock(); s.draining = true; s.mu.Unlock() }
func write(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}
func fail(w http.ResponseWriter, status int, code, id string) {
	write(w, status, map[string]any{"error": map[string]string{"code": code, "request_id": id}})
}
func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	start := time.Now()
	nonce := make([]byte, 16)
	if _, e := rand.Read(nonce); e != nil {
		fail(w, 503, "unavailable", "")
		return
	}
	id := hex.EncodeToString(nonce)
	w.Header().Set("X-Request-ID", id)
	s.mu.Lock()
	draining := s.draining
	s.mu.Unlock()
	if r.Method == "GET" && r.URL.Path == "/healthz" {
		write(w, 200, map[string]string{"status": "ok"})
		return
	}
	if r.Method == "GET" && r.URL.Path == "/readyz" {
		if draining || len(s.Auth.Key) < 32 {
			fail(w, 503, "unavailable", id)
			return
		}
		write(w, 200, map[string]string{"status": "ready", "contract": contracts.Version, "audience": s.Auth.Audience})
		return
	}
	operation := strings.TrimPrefix(r.URL.Path, "/v1/")
	known := false
	for _, op := range contracts.Operations {
		known = known || op == operation
	}
	if !known || r.Method != "POST" {
		fail(w, 404, "not_found", id)
		return
	}
	if draining {
		fail(w, 503, "unavailable", id)
		return
	}
	if r.Header.Get("Content-Type") != "application/json" {
		fail(w, 415, "json_required", id)
		return
	}
	body, e := io.ReadAll(http.MaxBytesReader(w, r.Body, contracts.MaxBody))
	defer clear(body)
	if e != nil {
		fail(w, 413, "input_limit", id)
		return
	}
	if !s.Auth.Verify(r, body) {
		fail(w, 401, "service_authentication_required", id)
		return
	}
	s.mu.Lock()
	if time.Since(s.window) >= time.Minute {
		s.window = time.Now()
		s.requests = 0
	}
	s.requests++
	allowed := s.requests <= 120
	s.mu.Unlock()
	if !allowed {
		w.Header().Set("Retry-After", "60")
		fail(w, 429, "service_rate_limited", id)
		return
	}
	select {
	case s.slots <- struct{}{}:
		defer func() { <-s.slots }()
	default:
		fail(w, 429, "service_busy", id)
		return
	}
	var input contracts.Request
	if contracts.Decode(body, &input) != nil || input.Operation != operation || input.Validate() != nil {
		fail(w, 422, "invalid_contract", id)
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 25*time.Second)
	defer cancel()
	if operation == "extract/deterministic" {
		write(w, 200, document.Extract(input.Fields, input.Text))
		return
	}
	result, e := s.Service.Analyze(ctx, input)
	status := 200
	code := "ok"
	if e != nil {
		status = 503
		code = "provider_unavailable"
		if errors.Is(e, ai.ErrRateLimited) {
			status = 429
			code = "provider_rate_limited"
			w.Header().Set("Retry-After", "60")
		}
		if errors.Is(e, context.DeadlineExceeded) || ctx.Err() != nil {
			status = 504
			code = "processing_timeout"
		}
		if errors.Is(e, ai.ErrUnsafe) {
			status = 502
			code = "provider_output_invalid"
		}
		fail(w, status, code, id)
	} else {
		write(w, 200, result)
	}
	if s.Logger != nil {
		s.Logger.Info("ai_request", "request_id", id, "operation", operation, "status", status, "code", code, "duration_ms", time.Since(start).Milliseconds())
	}
}
