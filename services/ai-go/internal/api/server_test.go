package api

import (
	"bytes"
	"context"
	"encoding/json"
	"flowmv/ai-go/internal/ai"
	"flowmv/ai-go/internal/contracts"
	"flowmv/ai-go/internal/security"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"
)

func fixture(operation string) contracts.Request {
	return contracts.Request{Contract: contracts.Version, RequestID: "request-1", Organization: "org-1", Actor: "actor-1", Connection: "connection-1", Workflow: "workflow-1", Operation: operation, Provider: "openai", Model: "gpt-5.4-mini", Credential: "FLOWMV_QA_AI_SYNTHETIC_ONLY", Text: "Employee ID: DEMO-1\nA leave request", Fields: []contracts.Field{{ID: "employee_id", Label: "Employee ID", Type: "identifier", Required: true}}, Categories: []string{"leave"}, Routes: []string{"leave_review"}, Template: "workplace-v1", MaxOutputTokens: 512}
}
func authorized(s *Server, path string, body []byte) *http.Request {
	r := httptest.NewRequest("POST", path, bytes.NewReader(body))
	stamp := strconv.FormatInt(time.Now().Unix(), 10)
	nonce := strings.Repeat("a", 64)
	r.Header.Set("Content-Type", "application/json")
	r.Header.Set("X-FlowMV-Time", stamp)
	r.Header.Set("X-FlowMV-Nonce", nonce)
	r.Header.Set("X-FlowMV-Audience", s.Auth.Audience)
	r.Header.Set("X-FlowMV-Signature", security.Signature(s.Auth.Key, r.Method, path, stamp, nonce, s.Auth.Audience, body))
	return r
}
func server(log io.Writer) *Server {
	return New(security.New([]byte(strings.Repeat("k", 32))), &ai.Service{Providers: map[string]ai.Provider{"openai": ai.Simulated{}, "gemini": ai.Simulated{}}, Simulated: true}, slog.New(slog.NewJSONHandler(log, nil)))
}
func TestEveryAIRouteAndSafeLogs(t *testing.T) {
	for _, op := range contracts.Operations {
		if op == "extract/deterministic" {
			continue
		}
		t.Run(op, func(t *testing.T) {
			var log bytes.Buffer
			s := server(&log)
			input := fixture(op)
			body, _ := json.Marshal(input)
			w := httptest.NewRecorder()
			s.ServeHTTP(w, authorized(s, "/v1/"+op, body))
			if w.Code != 200 {
				t.Fatalf("status %d: %s", w.Code, w.Body)
			}
			var result contracts.Response
			if json.Unmarshal(w.Body.Bytes(), &result) != nil || result.Source != "simulated" || result.Advice.Classification != "leave" {
				t.Fatal("bad REST result")
			}
			for _, secret := range []string{input.Credential, input.Text, "DEMO-1"} {
				if strings.Contains(log.String(), secret) {
					t.Fatal("sensitive log")
				}
			}
			if strings.Contains(w.Body.String(), input.Credential) {
				t.Fatal("credential echo")
			}
		})
	}
}
func TestForgedScopeLimitsAndUnknownInput(t *testing.T) {
	for _, test := range []struct {
		name   string
		body   []byte
		path   string
		auth   bool
		status int
	}{
		{"unsigned", []byte(`{}`), "/v1/summarize", false, 401},
		{"unknown", []byte(`{"credentials":"LEAK_SENTINEL","tool":"http"}`), "/v1/summarize", true, 422},
		{"scope", mustJSON(fixture("classify")), "/v1/summarize", true, 422},
		{"oversized", []byte(strings.Repeat("x", contracts.MaxBody+1)), "/v1/summarize", true, 413},
		{"no_arbitrary_http", []byte(`{}`), "/v1/http", true, 404},
	} {
		t.Run(test.name, func(t *testing.T) {
			s := server(io.Discard)
			r := authorized(s, test.path, test.body)
			if !test.auth {
				r.Header.Del("X-FlowMV-Signature")
			}
			w := httptest.NewRecorder()
			s.ServeHTTP(w, r)
			if w.Code != test.status || strings.Contains(w.Body.String(), "LEAK_SENTINEL") {
				t.Fatalf("unsafe failure %d", w.Code)
			}
		})
	}
}
func mustJSON(r contracts.Request) []byte { b, _ := json.Marshal(r); return b }
func TestBoundedConcurrencyAndDrain(t *testing.T) {
	s := server(io.Discard)
	for range cap(s.slots) {
		s.slots <- struct{}{}
	}
	r := fixture("summarize")
	w := httptest.NewRecorder()
	s.ServeHTTP(w, authorized(s, "/v1/summarize", mustJSON(r)))
	if w.Code != 429 {
		t.Fatal("concurrency unbounded")
	}
	s.Drain()
	w = httptest.NewRecorder()
	s.ServeHTTP(w, httptest.NewRequest("GET", "/readyz", nil))
	if w.Code != 503 {
		t.Fatal("draining service ready")
	}
}
func TestCancellationAndProviderFailures(t *testing.T) {
	for _, test := range []struct {
		text   string
		status int
	}{{"[SIMULATE_TIMEOUT]", 504}, {"[SIMULATE_RATE_LIMIT]", 429}} {
		s := server(io.Discard)
		r := fixture("summarize")
		r.Text = test.text
		w := httptest.NewRecorder()
		s.ServeHTTP(w, authorized(s, "/v1/summarize", mustJSON(r)))
		if w.Code != test.status {
			t.Fatalf("expected %d got %d", test.status, w.Code)
		}
	}
	s := server(io.Discard)
	r := fixture("summarize")
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	w := httptest.NewRecorder()
	s.ServeHTTP(w, authorized(s, "/v1/summarize", mustJSON(r)).WithContext(ctx))
	if w.Code != 504 {
		t.Fatal("cancellation ignored")
	}
}
