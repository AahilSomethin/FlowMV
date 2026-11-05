package security

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"strconv"
	"sync"
	"time"
)

type Auth struct {
	Key      []byte
	Audience string
	mu       sync.Mutex
	nonces   map[string]time.Time
	Clock    func() time.Time
}

func New(key []byte) *Auth {
	nonce := make([]byte, 32)
	if _, e := rand.Read(nonce); e != nil {
		panic("secure_random_unavailable")
	}
	return &Auth{Key: key, Audience: hex.EncodeToString(nonce), nonces: map[string]time.Time{}, Clock: time.Now}
}
func Signature(key []byte, method, path, timestamp, nonce, audience string, body []byte) string {
	hash := sha256.Sum256(body)
	m := hmac.New(sha256.New, key)
	m.Write([]byte(method + "\n" + path + "\n" + timestamp + "\n" + nonce + "\n" + audience + "\n" + hex.EncodeToString(hash[:])))
	return hex.EncodeToString(m.Sum(nil))
}
func (a *Auth) Verify(r *http.Request, body []byte) bool {
	stamp := r.Header.Get("X-FlowMV-Time")
	nonce := r.Header.Get("X-FlowMV-Nonce")
	aud := r.Header.Get("X-FlowMV-Audience")
	issued, e := strconv.ParseInt(stamp, 10, 64)
	now := a.Clock()
	if e != nil || len(a.Key) < 32 || len(nonce) != 64 || aud != a.Audience || r.URL.RawQuery != "" || now.Unix()-issued > 30 || issued-now.Unix() > 5 {
		return false
	}
	if _, e = hex.DecodeString(nonce); e != nil {
		return false
	}
	got, e := hex.DecodeString(r.Header.Get("X-FlowMV-Signature"))
	if e != nil {
		return false
	}
	expected, _ := hex.DecodeString(Signature(a.Key, r.Method, r.URL.Path, stamp, nonce, aud, body))
	if !hmac.Equal(got, expected) {
		return false
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	for n, expiry := range a.nonces {
		if !expiry.After(now) {
			delete(a.nonces, n)
		}
	}
	if _, ok := a.nonces[nonce]; ok || len(a.nonces) >= 10000 {
		return false
	}
	a.nonces[nonce] = now.Add(40 * time.Second)
	return true
}
