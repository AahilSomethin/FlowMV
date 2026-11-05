package ai

import (
	"context"
	"errors"
	"flowmv/ai-go/internal/contracts"
	"flowmv/ai-go/internal/document"
	"strings"
)

var ErrUnavailable = errors.New("provider_unavailable")
var ErrRateLimited = errors.New("provider_rate_limited")
var ErrUnsafe = errors.New("provider_output_invalid")

type Provider interface {
	Analyze(context.Context, contracts.Request) (contracts.Advice, int, int, error)
}
type Service struct {
	Providers map[string]Provider
	Simulated bool
}

func (s *Service) Analyze(ctx context.Context, r contracts.Request) (contracts.Response, error) {
	p, ok := s.Providers[r.Provider]
	if !ok {
		return contracts.Response{}, ErrUnavailable
	}
	a, in, out, e := p.Analyze(ctx, r)
	if e != nil {
		return contracts.Response{}, e
	}
	if e = a.Validate(r); e != nil || in < 0 || in > 262144 || out < 0 || out > r.MaxOutputTokens {
		return contracts.Response{}, ErrUnsafe
	}
	// A provider must never echo the transient credential into content returned to Laravel.
	for _, value := range append([]string{a.Summary, a.DraftReply}, mapValues(a.Fields)...) {
		if strings.Contains(value, r.Credential) {
			return contracts.Response{}, ErrUnsafe
		}
	}
	source := "provider_response"
	if s.Simulated {
		source = "simulated"
	}
	return contracts.Response{Contract: contracts.Version, RequestID: r.RequestID, Source: source, Provider: r.Provider, Model: r.Model, PromptVersion: "workplace-v1", InputTokens: in, OutputTokens: out, Advice: a}, nil
}
func mapValues(m map[string]string) []string {
	result := []string{}
	for _, v := range m {
		result = append(result, v)
	}
	return result
}

type Simulated struct{}

func (Simulated) Analyze(ctx context.Context, r contracts.Request) (contracts.Advice, int, int, error) {
	if e := ctx.Err(); e != nil {
		return contracts.Advice{}, 0, 0, e
	}
	if !strings.HasPrefix(r.Credential, "FLOWMV_QA_AI_") {
		return contracts.Advice{}, 0, 0, ErrUnavailable
	}
	if strings.Contains(r.Text, "[SIMULATE_TIMEOUT]") {
		return contracts.Advice{}, 0, 0, context.DeadlineExceeded
	}
	if strings.Contains(r.Text, "[SIMULATE_RATE_LIMIT]") {
		return contracts.Advice{}, 0, 0, ErrRateLimited
	}
	x := document.Extract(r.Fields, r.Text)
	a := contracts.Advice{Summary: "Synthetic workplace request; review the original evidence in FlowMV.", Classification: "unknown", Fields: x.Values, Missing: []string{}, Inconsistent: x.Uncertain, Recommendation: "continue_review", Uncertain: strings.Contains(r.Text, "[SIMULATE_UNCERTAIN]") || len(x.Uncertain) > 0}
	for _, f := range r.Fields {
		if f.Required && x.Values[f.ID] == "" {
			a.Missing = append(a.Missing, f.ID)
		}
	}
	if len(a.Missing) > 0 {
		a.Recommendation = "request_correction"
		a.DraftReply = "Please sign in to FlowMV to review and correct the missing information."
	}
	if a.Uncertain {
		a.Recommendation = "review"
	}
	for _, category := range r.Categories {
		if strings.Contains(strings.ToLower(r.Text), strings.ToLower(category)) {
			a.Classification = category
			break
		}
	}
	return a, (len(r.Text) + 3) / 4, 64, nil
}
