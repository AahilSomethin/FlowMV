package document

import (
	"flowmv/ai-go/internal/contracts"
	"strings"
)

type Extraction struct {
	Values    map[string]string `json:"values"`
	Uncertain []string          `json:"uncertain"`
}

// Extract matches the Laravel line/label contract on already authorized extracted text.
// It does not open files, parse ZIP/PDF, scan, or make authenticity judgments.
func Extract(fields []contracts.Field, text string) Extraction {
	result := Extraction{Values: map[string]string{}, Uncertain: []string{}}
	candidates := map[string]map[string]bool{}
	for _, line := range strings.Split(text, "\n") {
		if strings.ContainsRune(line, '\r') {
			continue
		}
		line = strings.TrimLeft(line, "\t ")
		for _, f := range fields {
			if f.Type == "supporting_document" || !strings.HasPrefix(line, f.Label) {
				continue
			}
			rest := strings.TrimLeft(strings.TrimPrefix(line, f.Label), "\t ")
			if !strings.HasPrefix(rest, ":") {
				continue
			}
			value := strings.Trim(strings.TrimPrefix(rest, ":"), " \t\n\r\x00\x0b")
			if candidates[f.ID] == nil {
				candidates[f.ID] = map[string]bool{}
			}
			candidates[f.ID][value] = true
		}
	}
	for _, f := range fields {
		values := candidates[f.ID]
		if len(values) > 1 {
			result.Uncertain = append(result.Uncertain, f.ID)
		} else {
			for value := range values {
				result.Values[f.ID] = value
			}
		}
	}
	return result
}
