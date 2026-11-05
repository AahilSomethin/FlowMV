package document

import (
	"flowmv/ai-go/internal/contracts"
	"reflect"
	"strings"
	"testing"
)

func TestLiteralMatchingAndConflicts(t *testing.T) {
	fields := []contracts.Field{{ID: "id", Label: "Employee ID", Type: "identifier"}, {ID: "date", Label: "Start.date", Type: "date"}, {ID: "file", Label: "Attachment", Type: "supporting_document"}}
	text := " Employee ID \t: DEMO-1 \nEmployee ID:DEMO-1\nStartXdate: ignored\nStart.date:2026-11-04\nStart.date:2026-11-05\nAttachment: ignored"
	x := Extract(fields, text)
	if !reflect.DeepEqual(x.Values, map[string]string{"id": "DEMO-1"}) || !reflect.DeepEqual(x.Uncertain, []string{"date"}) {
		t.Fatalf("unexpected extraction: %#v", x)
	}
}
func TestExistingPHPCRLFContract(t *testing.T) {
	if len(Extract([]contracts.Field{{ID: "id", Label: "ID", Type: "text"}}, "ID: DEMO-1\r\n").Values) != 0 {
		t.Fatal("changed published extraction behavior")
	}
}
func BenchmarkExtract(b *testing.B) {
	fields := []contracts.Field{}
	for _, id := range []string{"a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t"} {
		fields = append(fields, contracts.Field{ID: id, Label: "Field " + id, Type: "text"})
	}
	text := strings.Repeat("Synthetic irrelevant text\n", 3500) + "Field a: DEMO\n"
	b.ReportAllocs()
	b.ResetTimer()
	for b.Loop() {
		Extract(fields, text)
	}
}
