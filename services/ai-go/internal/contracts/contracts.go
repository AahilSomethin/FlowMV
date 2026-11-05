package contracts

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"regexp"
	"slices"
	"strings"
	"unicode/utf8"
)

const Version = "flowmv.ai.v1"
const MaxBody = 262144

var ID = regexp.MustCompile(`^[a-zA-Z0-9_-]{1,80}$`)
var Model = regexp.MustCompile(`^[a-zA-Z0-9._-]{1,80}$`)
var Operations = []string{"analyze/email", "analyze/document", "analyze/slack", "extract/fields", "classify", "summarize", "draft/reply", "extract/deterministic", "connections/test"}

type Field struct {
	ID       string `json:"id"`
	Label    string `json:"label"`
	Type     string `json:"type"`
	Required bool   `json:"required"`
}

type Request struct {
	Contract        string   `json:"contract"`
	RequestID       string   `json:"request_id"`
	Organization    string   `json:"organization"`
	Actor           string   `json:"actor"`
	Connection      string   `json:"connection"`
	Workflow        string   `json:"workflow"`
	Operation       string   `json:"operation"`
	Provider        string   `json:"provider"`
	Model           string   `json:"model"`
	Credential      string   `json:"credential"`
	Text            string   `json:"text"`
	Fields          []Field  `json:"fields"`
	Categories      []string `json:"categories"`
	Routes          []string `json:"routes"`
	Template        string   `json:"template"`
	MaxOutputTokens int      `json:"max_output_tokens"`
}

type Advice struct {
	Summary        string            `json:"summary"`
	Classification string            `json:"classification"`
	Fields         map[string]string `json:"fields"`
	Missing        []string          `json:"missing"`
	Inconsistent   []string          `json:"inconsistent"`
	Route          string            `json:"route"`
	DraftReply     string            `json:"draft_reply"`
	Recommendation string            `json:"recommendation"`
	Uncertain      bool              `json:"uncertain"`
}
type Response struct {
	Contract      string `json:"contract"`
	RequestID     string `json:"request_id"`
	Source        string `json:"source"`
	Provider      string `json:"provider"`
	Model         string `json:"model"`
	PromptVersion string `json:"prompt_version"`
	InputTokens   int    `json:"input_tokens"`
	OutputTokens  int    `json:"output_tokens"`
	Advice        Advice `json:"advice"`
}

var ErrInvalid = errors.New("invalid_contract")

// Decode rejects duplicate members, unknown fields, trailing values and excessive nesting.
func Decode(data []byte, target any) error {
	if !utf8.Valid(data) || len(data) > MaxBody {
		return ErrInvalid
	}
	d := json.NewDecoder(bytes.NewReader(data))
	var walk func(int) error
	walk = func(depth int) error {
		if depth > 16 {
			return ErrInvalid
		}
		t, e := d.Token()
		if e != nil {
			return ErrInvalid
		}
		if delim, ok := t.(json.Delim); ok {
			switch delim {
			case '{':
				seen := map[string]bool{}
				for d.More() {
					key, e := d.Token()
					s, ok := key.(string)
					if e != nil || !ok || seen[s] {
						return ErrInvalid
					}
					seen[s] = true
					if e = walk(depth + 1); e != nil {
						return e
					}
				}
			case '[':
				for d.More() {
					if e := walk(depth + 1); e != nil {
						return e
					}
				}
			default:
				return ErrInvalid
			}
			if _, e = d.Token(); e != nil {
				return ErrInvalid
			}
		}
		return nil
	}
	if e := walk(0); e != nil {
		return e
	}
	if _, e := d.Token(); e != io.EOF {
		return ErrInvalid
	}
	d = json.NewDecoder(bytes.NewReader(data))
	d.DisallowUnknownFields()
	if e := d.Decode(target); e != nil {
		return ErrInvalid
	}
	return nil
}

func SafeString(s string, max int) bool {
	return utf8.ValidString(s) && len(s) <= max && !strings.ContainsAny(s, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0b\x0c\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f")
}
func (r Request) Validate() error {
	if r.Contract != Version || !ID.MatchString(r.RequestID) || !ID.MatchString(r.Organization) || !ID.MatchString(r.Actor) || !ID.MatchString(r.Workflow) || !slices.Contains(Operations, r.Operation) || !SafeString(r.Text, 100000) || strings.TrimSpace(r.Text) == "" || len(r.Fields) > 20 || len(r.Categories) > 16 || len(r.Routes) > 16 {
		return ErrInvalid
	}
	seen := map[string]bool{}
	for _, f := range r.Fields {
		if !ID.MatchString(f.ID) || seen[f.ID] || !SafeString(f.Label, 160) || strings.TrimSpace(f.Label) == "" || !slices.Contains([]string{"text", "identifier", "email", "date", "number", "select", "supporting_document"}, f.Type) {
			return ErrInvalid
		}
		seen[f.ID] = true
	}
	for _, list := range [][]string{r.Categories, r.Routes} {
		for _, s := range list {
			if !ID.MatchString(s) {
				return ErrInvalid
			}
		}
	}
	if r.Operation == "extract/deterministic" {
		if r.Credential != "" || r.Provider != "" || r.Model != "" {
			return ErrInvalid
		}
		return nil
	}
	if !slices.Contains([]string{"openai", "gemini"}, r.Provider) || !ID.MatchString(r.Connection) || !Model.MatchString(r.Model) || !SafeString(r.Credential, 512) || strings.ContainsAny(r.Credential, "\r\n\t ") || len(r.Credential) < 12 || r.MaxOutputTokens < 256 || r.MaxOutputTokens > 4096 || r.Template != "workplace-v1" {
		return ErrInvalid
	}
	return nil
}
func (a Advice) Validate(r Request) error {
	if !SafeString(a.Summary, 2000) || !SafeString(a.DraftReply, 6000) || !slices.Contains([]string{"review", "request_correction", "continue_review"}, a.Recommendation) || (a.Classification != "unknown" && !slices.Contains(r.Categories, a.Classification)) || (a.Route != "" && !slices.Contains(r.Routes, a.Route)) || len(a.Fields) > 20 || len(a.Missing) > 20 || len(a.Inconsistent) > 20 {
		return ErrInvalid
	}
	fields := map[string]bool{}
	for _, f := range r.Fields {
		fields[f.ID] = true
		if f.Required && strings.TrimSpace(a.Fields[f.ID]) == "" && !slices.Contains(a.Missing, f.ID) {
			return ErrInvalid
		}
	}
	for k, v := range a.Fields {
		if !fields[k] || !SafeString(v, 4000) {
			return ErrInvalid
		}
	}
	for _, list := range [][]string{a.Missing, a.Inconsistent} {
		for _, v := range list {
			if !fields[v] {
				return ErrInvalid
			}
		}
	}
	return nil
}

// DecodeAdvice requires every schema member, including explicit uncertainty and usage-independent empty values.
func DecodeAdvice(data []byte, target *Advice) error {
	var members map[string]json.RawMessage
	if json.Unmarshal(data, &members) != nil {
		return ErrInvalid
	}
	for _, key := range []string{"summary", "classification", "fields", "missing", "inconsistent", "route", "draft_reply", "recommendation", "uncertain"} {
		value, ok := members[key]
		if !ok || bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return ErrInvalid
		}
	}
	return Decode(data, target)
}
