package security

import (
	"net/http/httptest"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

func TestScopeReplayAndRestart(t *testing.T) {
	a := New([]byte(strings.Repeat("k", 32)))
	body := []byte(`{"synthetic":true}`)
	request := httptest.NewRequest("POST", "/v1/summarize", nil)
	stamp := strconv.FormatInt(time.Now().Unix(), 10)
	nonce := strings.Repeat("a", 64)
	request.Header.Set("X-FlowMV-Time", stamp)
	request.Header.Set("X-FlowMV-Nonce", nonce)
	request.Header.Set("X-FlowMV-Audience", a.Audience)
	request.Header.Set("X-FlowMV-Signature", Signature(a.Key, "POST", "/v1/summarize", stamp, nonce, a.Audience, body))
	if a.Verify(request, []byte(`{"synthetic":false}`)) {
		t.Fatal("changed body authorized")
	}
	changed := request.Clone(request.Context())
	changed.URL.Path = "/v1/classify"
	if a.Verify(changed, body) {
		t.Fatal("changed operation authorized")
	}
	if New(a.Key).Verify(request, body) {
		t.Fatal("pre-restart request accepted")
	}
	if !a.Verify(request, body) {
		t.Fatal("valid request refused")
	}
	if a.Verify(request, body) {
		t.Fatal("replay accepted")
	}
}
func TestConcurrentReplayOnlyOneAdmission(t *testing.T) {
	a := New([]byte(strings.Repeat("k", 32)))
	body := []byte(`{}`)
	stamp := strconv.FormatInt(time.Now().Unix(), 10)
	nonce := strings.Repeat("b", 64)
	r := httptest.NewRequest("POST", "/v1/summarize", nil)
	r.Header.Set("X-FlowMV-Time", stamp)
	r.Header.Set("X-FlowMV-Nonce", nonce)
	r.Header.Set("X-FlowMV-Audience", a.Audience)
	r.Header.Set("X-FlowMV-Signature", Signature(a.Key, "POST", r.URL.Path, stamp, nonce, a.Audience, body))
	var accepted atomic.Int32
	var wg sync.WaitGroup
	for range 32 {
		wg.Go(func() {
			if a.Verify(r.Clone(r.Context()), body) {
				accepted.Add(1)
			}
		})
	}
	wg.Wait()
	if accepted.Load() != 1 {
		t.Fatalf("admitted %d", accepted.Load())
	}
}
func TestExpiredAndFutureRequests(t *testing.T) {
	for _, offset := range []int64{-31, 6} {
		a := New([]byte(strings.Repeat("k", 32)))
		stamp := strconv.FormatInt(time.Now().Unix()+offset, 10)
		nonce := strings.Repeat("c", 64)
		r := httptest.NewRequest("POST", "/v1/summarize", nil)
		r.Header.Set("X-FlowMV-Time", stamp)
		r.Header.Set("X-FlowMV-Nonce", nonce)
		r.Header.Set("X-FlowMV-Audience", a.Audience)
		r.Header.Set("X-FlowMV-Signature", Signature(a.Key, "POST", r.URL.Path, stamp, nonce, a.Audience, []byte(`{}`)))
		if a.Verify(r, []byte(`{}`)) {
			t.Fatal("expiry bypass")
		}
	}
}
