package ai

import (
	"context"
	"flowmv/ai-go/internal/contracts"
	"fmt"
	"strings"
	"testing"
)

type malicious struct{ credentials bool }

func (m malicious) Analyze(_ context.Context, r contracts.Request) (contracts.Advice, int, int, error) {
	a := contracts.Advice{Summary: r.Credential, Classification: "unknown", Fields: map[string]string{}, Recommendation: "review"}
	if !m.credentials {
		a.Summary = "review"
		a.Route = "attacker-controlled"
	}
	return a, 10, 20, nil
}
func TestSecretEchoAndAuthorityInjectionFailClosed(t *testing.T) {
	for _, credentials := range []bool{true, false} {
		s := Service{Providers: map[string]Provider{"openai": malicious{credentials}}}
		_, e := s.Analyze(context.Background(), contracts.Request{Provider: "openai", Credential: "FLOWMV_QA_AI_ONLY", MaxOutputTokens: 512})
		if e != ErrUnsafe || strings.Contains(e.Error(), "FLOWMV_QA") {
			t.Fatal("unsafe provider output accepted")
		}
	}
}
func BenchmarkSimulatedAdvisory(b *testing.B) {
	service := &Service{Providers: map[string]Provider{"openai": Simulated{}}, Simulated: true}
	request := contracts.Request{Provider: "openai", Credential: "FLOWMV_QA_AI_BENCHMARK_ONLY", Model: "synthetic-model", Operation: "extract/fields", MaxOutputTokens: 512, Text: strings.Repeat("Synthetic ordinary workplace text. ", 1800), Fields: []contracts.Field{}, Categories: []string{}, Routes: []string{}}
	for i := 0; i < 20; i++ {
		request.Fields = append(request.Fields, contracts.Field{ID: fmt.Sprintf("field_%d", i), Label: fmt.Sprintf("Field %d", i), Type: "text", Required: true})
		request.Text += fmt.Sprintf("\nField %d: synthetic value %d", i, i)
	}
	b.ReportAllocs()
	b.SetBytes(int64(len(request.Text)))
	b.RunParallel(func(pb *testing.PB) {
		for pb.Next() {
			if _, err := service.Analyze(context.Background(), request); err != nil {
				b.Fatal("synthetic advisory benchmark failed")
			}
		}
	})
}
